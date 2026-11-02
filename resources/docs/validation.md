# Validation

Never trust input. Validate it, then use **only the validated data**.

```php
public function store(Request $request): Response
{
    $data = $request->validate([
        'name' => 'required|string|max:100|no_html',
        'email' => 'required|email|max:255|unique:users,email',
        'password' => 'required|strong_password|confirmed',
        'age' => 'nullable|integer|between:18,120',
        'avatar' => 'nullable|image|max:2048',            // KB
        'code' => ['required', 'regex:/^[A-Z]{3}-\d{4}$/'], // array form when a rule contains "|"
    ]);

    User::create($data);   // only the fields above are in $data
}
```

On failure the user is **redirected back** with the errors and their input (passwords excluded). For API/AJAX requests a **422 JSON** response is returned instead:

```json
{ "message": "The given data was invalid.", "errors": { "email": ["The email must be a valid email address."] } }
```

## Showing errors

```blade
<input name="email" value="{{ old('email') }}" @class(['form-control', 'is-invalid' => error('email')])>
@error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
```

`errors()` returns all errors; `error('field')` the first message for a field.

## Available rules

| Category | Rules |
|---|---|
| Presence | `required`, `required_if:field,value`, `required_unless:field,value`, `required_with:field`, `nullable`, `sometimes`, `prohibited`, `bail` |
| Types | `string`, `integer`, `numeric`, `boolean`, `array`, `json`, `date`, `email`, `url`, `ip`, `uuid` |
| Text | `alpha`, `alpha_num`, `alpha_dash`, `lowercase`, `uppercase`, `starts_with:a,b`, `ends_with:a,b`, `regex:/.../`, `not_regex:/.../`, `no_html` |
| Size | `min:n`, `max:n`, `between:a,b`, `size:n`, `digits:n`, `digits_between:a,b` (characters for text, value for numbers, KB for files, items for arrays) |
| Comparison | `in:a,b,c`, `not_in:a,b`, `same:field`, `different:field`, `confirmed`, `gt:field`, `lt:field`, `before:date`, `after:date` |
| Database | `unique:table,column[,ignoreId[,idColumn]]`, `exists:table,column` |
| Files | `file`, `image`, `mimes:jpg,png,pdf` (checked against the REAL file content) |
| Security | `strong_password`, `current_password`, `accepted`, `no_html` |

### Security rules explained

- **`strong_password`** — at least 8 characters with upper case, lower case, a number and a symbol.
- **`current_password`** — must match the signed-in user's password (for "change password" forms).
- **`no_html`** — rejects any HTML tags or `<` `>` (names, titles...). Output is escaped anyway; this keeps the data clean.
- **`url`** — only `http://` and `https://` (so `javascript:` links are refused).
- **`image` / `mimes`** — the type is detected from the file's bytes, so `shell.php` renamed to `photo.jpg` fails.

### Unique when updating

Ignore the current row's own value:

```php
'email' => 'required|email|unique:users,email,' . $user->id,
```

## Custom messages and names

```php
$request->validate(
    ['dob' => 'required|date'],
    ['dob.required' => 'Tell us your birthday.'],    // messages: "field.rule" or "rule"
    ['dob' => 'date of birth'],                     // friendly field names
);
```

Default messages live in `lang/en/validation.php` — edit or translate them there.

## Manual validation

```php
$validator = Core\Validator::make($data, $rules);

if ($validator->fails()) {
    $errors = $validator->errors();   // ['email' => ['...']]
}
$clean = $validator->validated();
```
