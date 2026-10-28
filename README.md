# PHP Template Engine

A tiny dependency-free PHP template engine: templates compile to plain PHP and are cached on disk, so rendering a cached template is just an `include()`.

## Syntax

| Syntax | Meaning |
|---|---|
| `{{ name }}` | Escaped variable (`htmlspecialchars`, XSS-safe by default) |
| `{{{ name }}}` | Raw variable — unescaped, trusted HTML only |
| `{{ user.name }}` | Dotted paths for nested arrays (missing keys → `null`, never a notice) |
| `{% if … %} / {% elseif … %} / {% else %} / {% endif %}` | Conditionals |
| `{% for item in items %} … {% endfor %}` | Loops |
| `{% include 'partial' %}` | Include another template with the same scope |

## Usage

```php
require 'src/Template.php';

$tpl = new Template(__DIR__ . '/templates', __DIR__ . '/cache');
echo $tpl->render('welcome', [
    'user'  => ['name' => 'Azan', 'admin' => true],
    'posts' => [['title' => 'Hello', 'views' => 120]],
]);
```

Compiled files are regenerated automatically when a template's mtime changes. Run `examples/template-example.php` for a working demo.

## License

MIT
