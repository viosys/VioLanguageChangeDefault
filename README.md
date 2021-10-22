# Change default language

This plugins allows you to change the default language of your shopware 6 shop.
Empty values in the translations of the new default language will be filled with values from the old default langauges.

Change the default language by using the command:

```sh
php bin/console vio:language:change-default [locale key]
```

Example:

```sh
php bin/console vio:language:change-default de-DE
```
