<?php

declare(strict_types=1);

namespace Vio\LanguageChangeDefault\Commands;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DoctrineDriverException;
use Doctrine\DBAL\Exception;
use InvalidArgumentException;
use RuntimeException;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Language\LanguageDefinition;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\Locale\LocaleEntity;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Throwable;
use function array_key_exists;
use function count;
use function strlen;

class LanguageChangeDefaultCommand extends Command
{
    protected static $defaultName = 'vio:language:change-default';

    private EntityRepository $localeRepository;

    private EntityRepository $languageRepository;

    private Connection $connection;

    public function __construct(
        EntityRepository $localeRepository,
        EntityRepository $languageRepository,
        Connection $connection
    ) {
        parent::__construct();
        $this->localeRepository = $localeRepository;
        $this->languageRepository = $languageRepository;
        $this->connection = $connection;
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Change the system default language')
            ->addArgument('locale', InputArgument::REQUIRED, 'the locale for the new system default language');
    }

    protected function interact(InputInterface $input, OutputInterface $output): void
    {
        $helper = $this->getHelper('question');

        if (!$input->getArgument('locale')) {
            $localeList = $this->getLocales();

            $question = new ChoiceQuestion('Please choose a language?', array_keys($localeList));
            $localeLang = $helper->ask($input, $output, $question);

            if (count($localeList[$localeLang]) > 0) {
                $question = new ChoiceQuestion('Please choose a language code?', $localeList[$localeLang]);
                $locale = $helper->ask($input, $output, $question);
            } else {
                $locale = array_shift($localeList[$localeLang]);
            }

            $input->setArgument('locale', $locale);
        }
    }

    /**
     * @throws DoctrineDriverException
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->isInteractive()) {
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion("Are you sure you want to change the system default language?\nLoose of data is possible, please create a backup of the database! (yes/no) [yes]", true);
            if (!$helper->ask($input, $output, $question)) {
                return 0;
            }
        }
        $localeCode = $input->getArgument('locale');
        if (empty($localeCode)) {
            throw new InvalidArgumentException('argument locale shouldn\'t be empty');
        }
        $context = new Context(new SystemSource());

        $newLocale = $this->localeRepository->search(
            (new Criteria())
                ->addFilter(new EqualsFilter('code', $localeCode))
                ->addAssociation('translations'),
            $context
        )->first();
        if (!$newLocale instanceof LocaleEntity) {
            $output->writeln('<error>argument locale isn\'t a valid locale code</error>');

            return 1;
        }

        // if $currentDefaultLanguage is null there are more issues then we can solve
        /** @var LanguageEntity $currentDefaultLanguage */
        $currentDefaultLanguage = $this->languageRepository->search(
            (new Criteria([Defaults::LANGUAGE_SYSTEM]))
                ->addAssociation('locale'),
            $context
        )->first();

        $currentLocaleId = $currentDefaultLanguage->getLocaleId();
        $currentLocale = $currentDefaultLanguage->getLocale();
        if (!$currentLocale) {
            $output->writeln('<error>couldn\'t find current locale</error>');

            return 1;
        }
        $newDefaultLocaleId = $newLocale->getId();

        // locales match -> do nothing.
        if ($currentLocaleId === $newDefaultLocaleId) {
            $output->writeln(sprintf('<comment>nothing todo %s is already the system default language</comment>', $localeCode));

            return 0;
        }

        $newDefaultLanguageId = $this->languageRepository->searchIds(
            (new Criteria())
                ->addFilter(new EqualsFilter('localeId', $newDefaultLocaleId)),
            $context
        )->firstId();

        if (!$newDefaultLanguageId) {
            $newDefaultLanguageId = $this->createNewLanguageEntry($newLocale, $context);
        }

        $this->swapDefaultLanguageId($newDefaultLanguageId, $output);

        $output->writeln(sprintf('<info>system default language changed to %s</info>', $newLocale->getCode()));

        return 0;
    }

    private function getLocales(): array
    {
        $context = new Context(new SystemSource());
        /** @var LocaleEntity[] $locales */
        $locales = $this->localeRepository->search(
            (new Criteria())
                ->addAssociation('translations')
                ->addSorting(new FieldSorting('translations.name')),
            $context
        )->getEntities();
        $localeList = [];

        foreach ($locales as $locale) {
            if (!array_key_exists($locale->getName(), $localeList)) {
                $localeList[$locale->getName()] = [];
            }
            $localeList[$locale->getName()][] = $locale->getCode();
        }

        return $localeList;
    }

    private function createNewLanguageEntry(LocaleEntity $locale, Context $context): string
    {
        $ids = $this->languageRepository->create([[
            'localeId' => $locale->getId(),
            'name' => $locale->getTranslation('name'),
            'translationCodeId' => $locale->getId(),
        ]], $context)
            ->getPrimaryKeys(LanguageDefinition::ENTITY_NAME);

        return array_shift($ids);
    }

    /**
     * @throws DoctrineDriverException
     * @throws Exception
     */
    private function swapDefaultLanguageId(string $newLanguageId, OutputInterface $output): void
    {
        $output->writeln('<info>switch default language</info>');
        $progressBarUpdateLang = new ProgressBar($output);
        $progressBarUpdateLang->setFormat("%current%/%max% [%bar%] <info>%percent:3s%%</info> %elapsed:6s%/%estimated:-6s% %memory:6s%");
        $progressBarUpdateLang->setMaxSteps(3);
        if(!$this->connection->beginTransaction()) {
            throw new RuntimeException('couldn\'t start a transaction!');
        }
        $this->connection->setAutoCommit(false);

        try{
            // $this->connection->executeStatement('SET foreign_key_checks = 0;');
            $stmt = $this->connection->prepare(
                'UPDATE language
             SET id = :newId
             WHERE id = :oldId'
            );

            $tmpId = Uuid::randomBytes();

            // assign new uuid to old DEFAULT
            $stmt->execute([
                'newId' => $tmpId,
                'oldId' => Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM),
            ]);
            $progressBarUpdateLang->advance();
            // change id to DEFAULT
            $stmt->execute([
                'newId' => Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM),
                'oldId' => Uuid::fromHexToBytes($newLanguageId),
            ]);
            $progressBarUpdateLang->advance();
            $stmt->execute([
                'newId' => Uuid::fromHexToBytes($newLanguageId),
                'oldId' => $tmpId,
            ]);
            $progressBarUpdateLang->finish();

            // get all referencing Table
            $sql = <<<SQL
SELECT
    i1.TABLE_NAME,
    i1.COLUMN_NAME,
    i1.CONSTRAINT_NAME,
    i1.REFERENCED_TABLE_NAME,
    i1.REFERENCED_COLUMN_NAME,
    GROUP_CONCAT(DISTINCT i2.COLUMN_NAME) as PRIMARY_KEYS
FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE i1
         JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE i2 ON (
            i1.TABLE_NAME = i2.TABLE_NAME AND
            i2.CONSTRAINT_NAME = 'PRIMARY'
    )
WHERE
        i1.REFERENCED_TABLE_SCHEMA = :database AND
        i1.REFERENCED_TABLE_NAME = 'language' AND
        i1.REFERENCED_COLUMN_NAME = 'id' AND
        i1.TABLE_NAME LIKE '%_translation'
GROUP BY i1.TABLE_NAME,
         i1.COLUMN_NAME,
         i1.CONSTRAINT_NAME,
         i1.REFERENCED_TABLE_NAME,
         i1.REFERENCED_COLUMN_NAME;
SQL;

            $tables = $this->connection->fetchAllAssociative(
                $sql,
                [
                    'database' => $this->connection->getDatabase(),
                ]
            );

            $output->writeln('<info>refactor translation tables</info>');
            $progressBarTable = new ProgressBar($output);
            $progressBarTable->setFormat("%current%/%max% [%bar%] <info>%percent:3s%%</info> %elapsed:6s%/%estimated:-6s% %memory:6s%");
            $progressBarTable->setMaxSteps(count($tables));

            foreach ($tables as $table) {
                $tableName = $table['TABLE_NAME'];
                $defaultLangId = Defaults::LANGUAGE_SYSTEM;
                $langReferenceColumnName = $table['COLUMN_NAME'];
                $primaryKeyColumns = array_filter(
                    explode(',', $table['PRIMARY_KEYS']),
                    static function (string $columnName) use ($langReferenceColumnName) {
                        return $columnName !== $langReferenceColumnName;
                    }
                );

                // select translations with no default lang
                $select = implode(',', array_map(static function (string $columnName) {
                    return 't1.' . $columnName;
                }, $primaryKeyColumns));
                $where = implode(' AND ', array_map(static function (string $columnName) {
                    return 't1.' . $columnName . ' = t2.' . $columnName;
                }, $primaryKeyColumns));
                $sql = <<<SQL
                SELECT $select
                FROM $tableName t1
                WHERE '$defaultLangId' NOT IN (
                    SELECT HEX($langReferenceColumnName)
                    FROM $tableName t2
                    WHERE $where
                );
SQL;
                $this->connection->executeStatement('SET foreign_key_checks = OFF;');
                $toSwitchTranslations = $this->connection->fetchAllAssociative($sql);
                if (!empty($toSwitchTranslations)) {
                    // move translations with no default lang
                    foreach ($toSwitchTranslations as $toSwitchTranslation) {
                        $toSwitchTranslation[$langReferenceColumnName] = Uuid::fromHexToBytes($newLanguageId);
                        $this->connection->executeStatement('SET foreign_key_checks = OFF;');
                        $this->connection->update(
                            $tableName,
                            [
                                $langReferenceColumnName => Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM),
                            ],
                            $toSwitchTranslation
                        );
                    }
                }

                // check default language has empty fields, and fill it with value from former default lang
                // get columns
                $columns = $this->connection->executeQuery("SHOW COLUMNS FROM " . $tableName)
                    ->fetchAllAssociative();
                $columns = array_filter($columns, static function(array $column) use ($langReferenceColumnName, $primaryKeyColumns) {
                    return $column['Field'] !== $langReferenceColumnName && !in_array($column['Field'], $primaryKeyColumns, true);
                });
                $setPart = implode(' , ', array_map(static function($column) {
                    $columnName = $column['Field'];
                    return "t1.$columnName = IF(t1.$columnName IS NULL, t2.$columnName, t1.$columnName)";
                }, $columns));

                $columnUpdateSql = <<< SQL
UPDATE $tableName t1
    INNER JOIN $tableName t2
        ON $where AND t1.$langReferenceColumnName != t2.$langReferenceColumnName
SET $setPart
WHERE t1.$langReferenceColumnName = :defaultLangID AND t2.$langReferenceColumnName = :oldDefaultLangId;
SQL;
                $this->connection->executeStatement($columnUpdateSql, [
                    'defaultLangID' => Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM),
                    'oldDefaultLangId' => Uuid::fromHexToBytes($newLanguageId)
                ]);
                $progressBarTable->advance();
            }

            $this->connection->executeStatement('SET foreign_key_checks = ON;');
            $this->connection->commit();
            $progressBarTable->finish();
        }
        catch (Throwable $throwable) {
            $this->connection->rollBack();
            throw new RuntimeException('couldn\'t change system default language!', 0, $throwable);
        }
    }
}
