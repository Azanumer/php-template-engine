<?php
/**
 * Template engine demo — writes two templates, renders one with data,
 * and shows that escaped output stays escaped (XSS-safe by default).
 */
require __DIR__ . '/../src/Template.php';

$templates = __DIR__ . '/templates';
$cache     = __DIR__ . '/cache';
@mkdir($templates, 0755, true);
@mkdir($cache, 0755, true);

file_put_contents($templates . '/welcome.tpl', <<<'TPL'
<h1>Hello, {{ user.name }}!</h1>
{% if user.admin %}
<p>You are an admin.</p>
{% else %}
<p>Welcome back, regular user.</p>
{% endif %}
<ul>
{% for post in posts %}
  <li>{{ post.title }} ({{ post.views }} views)</li>
{% endfor %}
</ul>
<p>Raw HTML passes through only with triple braces: {{{ trusted_html }}}</p>
TPL
);

$tpl = new Template($templates, $cache);

echo $tpl->render('welcome', [
    'user'         => ['name' => '<script>alert(1)</script>', 'admin' => false],
    'posts'        => [
        ['title' => 'First post', 'views' => 120],
        ['title' => 'Second post', 'views' => 45],
    ],
    'trusted_html' => '<strong>bold</strong>',
]);
