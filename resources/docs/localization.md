# Localization

Put translations in `lang/{locale}/{file}.php`:

```php
// lang/en/messages.php
return [
    'welcome' => 'Welcome, :name!',
    'apples' => '{0} No apples|{1} One apple|[2,*] :count apples',
];

// lang/fr/messages.php
return ['welcome' => 'Bienvenue, :name !'];
```

```php
__('messages.welcome', ['name' => $user->name]);
trans_choice('messages.apples', 3);       // "3 apples"
```

```blade
{{ __('messages.welcome', ['name' => auth()->name]) }}
@lang('auth.failed')
```

`:name` is replaced; `:Name` and `:NAME` give capitalised versions.

Sentence-style keys can live in `lang/fr.json`:

```json
{ "Save changes": "Enregistrer" }
```

## Choosing the language

```ini
APP_LOCALE=en
```

Per user (e.g. from a language switcher), store it in the session; it's applied on every request:

```php
Core\Session::set('locale', 'fr');
Core\Lang::setLocale('fr');      // for the current request
```

Missing translations fall back to `fallback_locale` (English), then to the key itself.

The framework's own texts are in `lang/en/auth.php`, `lang/en/passwords.php` and `lang/en/validation.php` — copy them to another folder to translate.
