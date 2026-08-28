<?php

declare(strict_types=1);

namespace WifiManager;

final class View
{
    public function __construct(
        private readonly string $root,
        private readonly Config $config,
        private readonly Auth $auth,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = [], bool $layout = true): void
    {
        $templatePath = $this->root . '/app/views/' . $template . '.php';
        if (!is_file($templatePath)) {
            throw new \RuntimeException('Šablona nebyla nalezena: ' . $template);
        }

        $config = $this->config;
        $auth = $this->auth;
        extract($data, EXTR_SKIP);
        ob_start();
        require $templatePath;
        $content = (string) ob_get_clean();

        if (!$layout) {
            echo $content;
            return;
        }

        require $this->root . '/app/views/layout.php';
    }
}

