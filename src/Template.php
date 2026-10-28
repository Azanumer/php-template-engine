<?php
/**
 * Template — a tiny dependency-free PHP template engine.
 *
 * Syntax:
 *   {{ name }}          escaped variable (htmlspecialchars, ENT_QUOTES)
 *   {{{ name }}}        raw variable (unescaped — use only for trusted HTML)
 *   {% if user %} ... {% elseif other %} ... {% else %} ... {% endif %}
 *   {% for item in items %} ... {% endfor %}
 *   {% include 'partial.tpl' %}
 *
 * Templates are compiled to plain PHP and cached on disk, so rendering a
 * cached template is just an include(). Compilation happens once per
 * template mtime change.
 */
class Template
{
    /** @var string Directory holding .tpl source files. */
    private $templatesDir;

    /** @var string Directory for compiled PHP cache files (must be writable). */
    private $cacheDir;

    /**
     * @param string $templatesDir Directory with .tpl files.
     * @param string $cacheDir     Writable directory for compiled templates.
     */
    public function __construct($templatesDir, $cacheDir)
    {
        $this->templatesDir = rtrim($templatesDir, '/');
        $this->cacheDir     = rtrim($cacheDir, '/');
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }

    /**
     * Render a template with the given variables.
     *
     * @param string $name Template name without extension (e.g. 'welcome').
     * @param array  $data Variables available inside the template.
     * @return string Rendered output.
     */
    public function render($name, array $data = [])
    {
        $compiled = $this->compile($name);
        extract($data, EXTR_SKIP); // Variables become $name, $items, ...
        ob_start();
        include $compiled;
        return ob_get_clean();
    }

    /**
     * Compile a template (or reuse the cached version when up to date).
     *
     * @param string $name Template name without extension.
     * @return string Path to the compiled PHP file.
     */
    private function compile($name)
    {
        $source = $this->templatesDir . '/' . $name . '.tpl';
        if (!is_file($source)) {
            throw new InvalidArgumentException("Template not found: {$name}");
        }
        $compiled = $this->cacheDir . '/' . md5($source) . '.php';
        if (is_file($compiled) && filemtime($compiled) >= filemtime($source)) {
            return $compiled;
        }

        $php = $this->compileSource(file_get_contents($source));
        file_put_contents($compiled, $php, LOCK_EX);
        return $compiled;
    }

    /**
     * Translate template syntax into PHP. Order matters: raw output first,
     * then escaped output, then control structures, then includes.
     */
    private function compileSource($source)
    {
        $php = $source;

        // Raw output: {{{ name }}} — no escaping (trusted HTML only).
        $php = preg_replace_callback(
            '/\{\{\{\s*([a-zA-Z_][a-zA-Z0-9_\.]*)\s*\}\}\}/',
            function ($m) {
                return '<?php echo ' . $this->variableExpr($m[1]) . '; ?>';
            },
            $php
        );

        // Escaped output: {{ name }}.
        $php = preg_replace_callback(
            '/\{\{\s*([a-zA-Z_][a-zA-Z0-9_\.]*)\s*\}\}/',
            function ($m) {
                return '<?php echo htmlspecialchars(' . $this->variableExpr($m[1]) . ', ENT_QUOTES, \'UTF-8\'); ?>';
            },
            $php
        );

        // Conditionals — the raw condition is translated so dotted paths
        // (user.admin) and bare variables (logged_in) become valid PHP.
        $php = preg_replace_callback(
            '/\{%\s*if\s+(.+?)\s*%\}/',
            function ($m) {
                return '<?php if (' . $this->translateCondition($m[1]) . '): ?>';
            },
            $php
        );
        $php = preg_replace_callback(
            '/\{%\s*elseif\s+(.+?)\s*%\}/',
            function ($m) {
                return '<?php elseif (' . $this->translateCondition($m[1]) . '): ?>';
            },
            $php
        );
        $php = preg_replace('/\{%\s*else\s*%\}/', '<?php else: ?>', $php);
        $php = preg_replace('/\{%\s*endif\s*%\}/', '<?php endif; ?>', $php);

        // Loops: {% for item in items %} — missing/non-array source iterates zero times.
        $php = preg_replace_callback(
            '/\{%\s*for\s+([a-zA-Z_][a-zA-Z0-9_]*)\s+in\s+([a-zA-Z_][a-zA-Z0-9_\.]*)\s*%\}/',
            function ($m) {
                return '<?php foreach ((' . $this->variableExpr($m[2]) . ' ?? []) as $' . $m[1] . '): ?>';
            },
            $php
        );
        $php = preg_replace('/\{%\s*endfor\s*%\}/', '<?php endforeach; ?>', $php);

        // Includes: {% include 'partial.tpl' %} — rendered with the same scope.
        $php = preg_replace_callback(
            '/\{%\s*include\s+\'([a-zA-Z0-9_\-\/]+)\'\s*%\}/',
            function ($m) {
                return '<?php echo $this->render(\'' . $m[1] . '\', get_defined_vars()); ?>';
            },
            $php
        );

        return $php;
    }

    /**
     * Translate a {% if %}/{% elseif %} condition into PHP: dotted paths
     * become guarded variable expressions, bare names become guarded $vars,
     * while keywords (true/false/null/and/or) and function calls are left alone.
     * Single pass so replacement text is never re-scanned.
     */
    private function translateCondition($expr)
    {
        return preg_replace_callback(
            '/[a-zA-Z_][a-zA-Z0-9_]*(?:\.[a-zA-Z_][a-zA-Z0-9_]*)+|\b[a-zA-Z_][a-zA-Z0-9_]*\b(?!\s*\()/',
            function ($m) {
                $token = $m[0];
                if (strpos($token, '.') !== false) {
                    return $this->variableExpr($token);
                }
                if (in_array(strtolower($token), ['true', 'false', 'null', 'and', 'or', 'xor'], true)) {
                    return $token;
                }
                return '(isset($' . $token . ') ? $' . $token . ' : null)';
            },
            $expr
        );
    }

    /**
     * Turn a dotted path like "user.name" into a safe PHP expression.
     * Missing keys resolve to null instead of raising a notice, so a
     * template never breaks on absent data.
     */
    private function variableExpr($path)
    {
        $parts = explode('.', $path);
        $expr  = '$' . array_shift($parts);
        foreach ($parts as $part) {
            $expr = '(is_array(' . $expr . ') && array_key_exists(\'' . $part . '\', ' . $expr . ') ? ' . $expr . '[\'' . $part . '\'] : null)';
        }
        // Top-level variable itself may be undefined — guard it too.
        if (count(explode('.', $path)) === 1) {
            $expr = '(isset(' . $expr . ') ? ' . $expr . ' : null)';
        }
        return $expr;
    }
}
