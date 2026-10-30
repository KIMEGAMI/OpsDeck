<?php

namespace Tests\Unit;

use Illuminate\Support\Str;
use Tests\TestCase;

class ConfigurationEnvironmentTemplateTest extends TestCase
{
    public function test_support_email_falls_back_when_contact_environment_variables_are_blank(): void
    {
        $variables = ['CONTACT_TO_ADDRESS', 'OPSDECK_SUPPORT_EMAIL'];
        $originalValues = [];

        foreach ($variables as $name) {
            $originalValues[$name] = [
                'process' => getenv($name),
                'environment' => $_ENV[$name] ?? null,
                'has_environment' => array_key_exists($name, $_ENV),
                'server' => $_SERVER[$name] ?? null,
                'has_server' => array_key_exists($name, $_SERVER),
            ];
        }

        try {
            foreach ($variables as $name) {
                putenv($name.'=');
                $_ENV[$name] = '';
                $_SERVER[$name] = '';
            }

            $configuration = require dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'opsdeck.php';

            self::assertSame('support@kimegami.jp', $configuration['support_email']);

            putenv('OPSDECK_SUPPORT_EMAIL=legacy-support@example.test');
            $_ENV['OPSDECK_SUPPORT_EMAIL'] = 'legacy-support@example.test';
            $_SERVER['OPSDECK_SUPPORT_EMAIL'] = 'legacy-support@example.test';
            $configuration = require dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'opsdeck.php';

            self::assertSame('legacy-support@example.test', $configuration['support_email']);

            putenv('CONTACT_TO_ADDRESS=contact@example.test');
            $_ENV['CONTACT_TO_ADDRESS'] = 'contact@example.test';
            $_SERVER['CONTACT_TO_ADDRESS'] = 'contact@example.test';
            $configuration = require dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'opsdeck.php';

            self::assertSame('contact@example.test', $configuration['support_email']);
        } finally {
            foreach ($originalValues as $name => $originalValue) {
                if (is_string($originalValue['process'])) {
                    putenv($name.'='.$originalValue['process']);
                } else {
                    putenv($name);
                }

                if ($originalValue['has_environment']) {
                    $_ENV[$name] = $originalValue['environment'];
                } else {
                    unset($_ENV[$name]);
                }

                if ($originalValue['has_server']) {
                    $_SERVER[$name] = $originalValue['server'];
                } else {
                    unset($_SERVER[$name]);
                }
            }
        }
    }

    public function test_enabling_resend_idempotent_mail_uses_the_api_without_renaming_mail_variables(): void
    {
        $mailPassword = Str::random();
        $variables = ['MAIL_MAILER', 'MAIL_HOST', 'MAIL_USERNAME', 'MAIL_PASSWORD', 'RESEND_IDEMPOTENT_MAIL_ENABLED'];
        $originalValues = [];

        foreach ($variables as $name) {
            $originalValues[$name] = [
                'process' => getenv($name),
                'environment' => $_ENV[$name] ?? null,
                'has_environment' => array_key_exists($name, $_ENV),
                'server' => $_SERVER[$name] ?? null,
                'has_server' => array_key_exists($name, $_SERVER),
            ];
        }

        try {
            foreach (['MAIL_MAILER' => 'smtp', 'MAIL_HOST' => 'smtp.other.test', 'MAIL_USERNAME' => 'smtp-user', 'MAIL_PASSWORD' => $mailPassword, 'RESEND_IDEMPOTENT_MAIL_ENABLED' => 'true'] as $name => $value) {
                putenv($name.'='.$value);
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }

            $configuration = require dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'mail.php';

            self::assertSame('resend_idempotent', $configuration['default']);
            self::assertSame('smtp', $configuration['mailers']['smtp']['transport']);
            self::assertTrue($configuration['mailers']['resend_idempotent']['enabled']);
            self::assertSame($mailPassword, $configuration['mailers']['smtp']['password']);
            self::assertSame($mailPassword, $configuration['mailers']['resend_idempotent']['key']);

            putenv('RESEND_IDEMPOTENT_MAIL_ENABLED=false');
            $_ENV['RESEND_IDEMPOTENT_MAIL_ENABLED'] = 'false';
            $_SERVER['RESEND_IDEMPOTENT_MAIL_ENABLED'] = 'false';
            $configuration = require dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'mail.php';

            self::assertSame('smtp', $configuration['default']);
        } finally {
            foreach ($originalValues as $name => $originalValue) {
                if (is_string($originalValue['process'])) {
                    putenv($name.'='.$originalValue['process']);
                } else {
                    putenv($name);
                }

                if ($originalValue['has_environment']) {
                    $_ENV[$name] = $originalValue['environment'];
                } else {
                    unset($_ENV[$name]);
                }

                if ($originalValue['has_server']) {
                    $_SERVER[$name] = $originalValue['server'];
                } else {
                    unset($_SERVER[$name]);
                }
            }
        }
    }

    public function test_resend_smtp_configuration_automatically_uses_idempotent_api_with_existing_mail_variables(): void
    {
        $mailPassword = Str::random();
        $variables = ['MAIL_MAILER', 'MAIL_HOST', 'MAIL_USERNAME', 'MAIL_PASSWORD', 'RESEND_IDEMPOTENT_MAIL_ENABLED'];
        $originalValues = [];

        foreach ($variables as $name) {
            $originalValues[$name] = [
                'process' => getenv($name),
                'environment' => $_ENV[$name] ?? null,
                'has_environment' => array_key_exists($name, $_ENV),
                'server' => $_SERVER[$name] ?? null,
                'has_server' => array_key_exists($name, $_SERVER),
            ];
        }

        try {
            foreach (['MAIL_MAILER' => 'smtp', 'MAIL_HOST' => 'smtp.resend.com', 'MAIL_USERNAME' => 'resend', 'MAIL_PASSWORD' => $mailPassword, 'RESEND_IDEMPOTENT_MAIL_ENABLED' => 'false'] as $name => $value) {
                putenv($name.'='.$value);
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }

            $configuration = require dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'mail.php';

            self::assertSame('resend_idempotent', $configuration['default']);
            self::assertTrue($configuration['mailers']['resend_idempotent']['enabled']);
            self::assertSame('smtp.resend.com', $configuration['mailers']['smtp']['host']);
            self::assertSame('resend', $configuration['mailers']['smtp']['username']);
            self::assertSame($mailPassword, $configuration['mailers']['smtp']['password']);
            self::assertSame($mailPassword, $configuration['mailers']['resend_idempotent']['key']);
        } finally {
            foreach ($originalValues as $name => $originalValue) {
                if (is_string($originalValue['process'])) {
                    putenv($name.'='.$originalValue['process']);
                } else {
                    putenv($name);
                }

                if ($originalValue['has_environment']) {
                    $_ENV[$name] = $originalValue['environment'];
                } else {
                    unset($_ENV[$name]);
                }

                if ($originalValue['has_server']) {
                    $_SERVER[$name] = $originalValue['server'];
                } else {
                    unset($_SERVER[$name]);
                }
            }
        }
    }

    public function test_local_environment_template_and_start_command_use_the_opsdeck_port(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $environmentTemplate = file_get_contents($projectRoot.DIRECTORY_SEPARATOR.'.env.example');
        $readme = file_get_contents($projectRoot.DIRECTORY_SEPARATOR.'README.md');
        self::assertNotFalse($environmentTemplate);
        self::assertNotFalse($readme);
        preg_match('/^APP_URL=(?<value>.*)$/m', $environmentTemplate, $matches);

        self::assertSame('http://localhost:8007', trim($matches['value'] ?? ''));
        preg_match('/^GOOGLE_LOGIN_REDIRECT_URI=(?<value>.*)$/m', $environmentTemplate, $redirectMatches);
        self::assertSame('http://localhost:8007/auth/google/callback', trim($redirectMatches['value'] ?? ''));
        self::assertStringContainsString('php artisan serve --host=127.0.0.1 --port=8007', $readme);
    }

    public function test_production_email_setup_documents_the_existing_resend_smtp_variables(): void
    {
        $readme = file_get_contents(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'README.md');
        $environmentTemplate = file_get_contents(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'.env.example');
        self::assertNotFalse($readme);
        self::assertNotFalse($environmentTemplate);

        foreach ([
            'MAIL_MAILER=smtp',
            'MAIL_HOST=smtp.resend.com',
            'MAIL_PORT=587',
            'MAIL_SCHEME=smtp',
            'MAIL_USERNAME=resend',
            'MAIL_PASSWORD=<ResendのSMTP認証キー>',
            'MAIL_FROM_ADDRESS=info@kimegami.jp',
            'CONTACT_TO_ADDRESS=support@kimegami.jp',
            'MAIL_DELIVERY_JOB_BACKOFF_SECONDS=30,120',
        ] as $setting) {
            self::assertStringContainsString($setting, $readme);
        }

        self::assertMatchesRegularExpression('/^CONTACT_TO_ADDRESS="support@kimegami\.jp"$/m', $environmentTemplate);
        self::assertStringNotContainsString('RESEND_API_KEY=<', $readme);
    }

    public function test_local_backup_retention_uses_thirty_days_for_unset_or_invalid_values(): void
    {
        $name = 'OPSDECK_BACKUP_LOCAL_RETENTION_DAYS';
        $originalProcessValue = getenv($name);
        $originalEnvironmentValue = $_ENV[$name] ?? null;
        $hadEnvironmentValue = array_key_exists($name, $_ENV);
        $originalServerValue = $_SERVER[$name] ?? null;
        $hadServerValue = array_key_exists($name, $_SERVER);

        try {
            foreach ([null, '', '0', '-1', 'invalid'] as $value) {
                if ($value === null) {
                    putenv($name);
                    unset($_ENV[$name], $_SERVER[$name]);
                } else {
                    putenv($name.'='.$value);
                    $_ENV[$name] = $value;
                    $_SERVER[$name] = $value;
                }

                $configuration = require dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'opsdeck.php';

                self::assertSame(30, $configuration['backup']['local_retention_days']);
            }

            putenv($name.'=45');
            $_ENV[$name] = '45';
            $_SERVER[$name] = '45';
            $configuration = require dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'opsdeck.php';

            self::assertSame(45, $configuration['backup']['local_retention_days']);
        } finally {
            if (is_string($originalProcessValue)) {
                putenv($name.'='.$originalProcessValue);
            } else {
                putenv($name);
            }

            if ($hadEnvironmentValue) {
                $_ENV[$name] = $originalEnvironmentValue;
            } else {
                unset($_ENV[$name]);
            }

            if ($hadServerValue) {
                $_SERVER[$name] = $originalServerValue;
            } else {
                unset($_SERVER[$name]);
            }
        }
    }

    public function test_backup_path_in_environment_template_uses_safe_application_default_when_unset(): void
    {
        $environmentTemplate = file_get_contents(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'.env.example');
        self::assertNotFalse($environmentTemplate);
        preg_match('/^OPSDECK_BACKUP_PATH=(?<value>.*)$/m', $environmentTemplate, $matches);

        self::assertSame('', trim($matches['value'] ?? 'The backup path must be explicitly documented.'));
    }

    public function test_ai_mapping_environment_template_documents_credentials_but_stays_disabled_by_default(): void
    {
        $environmentTemplate = file_get_contents(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'.env.example');
        self::assertNotFalse($environmentTemplate);

        self::assertMatchesRegularExpression('/^OPSDECK_AI_ENABLED=false$/m', $environmentTemplate);
        self::assertMatchesRegularExpression('/^OPSDECK_AI_PROVIDER=none$/m', $environmentTemplate);
        self::assertMatchesRegularExpression('/^OPENAI_API_KEY=$/m', $environmentTemplate);
        self::assertMatchesRegularExpression('/^OPENAI_API_ENDPOINT=https:\/\/api\.openai\.com\/v1\/responses$/m', $environmentTemplate);
    }

    public function test_storage_addon_stays_disabled_by_default_but_can_be_explicitly_enabled(): void
    {
        $name = 'OPSDECK_RELEASE_STORAGE_ADDON';
        $originalProcessValue = getenv($name);
        $originalEnvironmentValue = $_ENV[$name] ?? null;
        $hadEnvironmentValue = array_key_exists($name, $_ENV);
        $originalServerValue = $_SERVER[$name] ?? null;
        $hadServerValue = array_key_exists($name, $_SERVER);

        try {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
            $configuration = require dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'opsdeck.php';
            self::assertSame(false, $configuration['release_features']['storage_addon']);

            putenv($name.'=true');
            $_ENV[$name] = 'true';
            $_SERVER[$name] = 'true';
            $configuration = require dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'opsdeck.php';
            self::assertSame(true, $configuration['release_features']['storage_addon']);
        } finally {
            if (is_string($originalProcessValue)) {
                putenv($name.'='.$originalProcessValue);
            } else {
                putenv($name);
            }

            if ($hadEnvironmentValue) {
                $_ENV[$name] = $originalEnvironmentValue;
            } else {
                unset($_ENV[$name]);
            }

            if ($hadServerValue) {
                $_SERVER[$name] = $originalServerValue;
            } else {
                unset($_SERVER[$name]);
            }
        }

        $environmentTemplate = file_get_contents(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'.env.example');
        self::assertNotFalse($environmentTemplate);
        self::assertMatchesRegularExpression('/^OPSDECK_RELEASE_STORAGE_ADDON=false$/m', $environmentTemplate);
        self::assertMatchesRegularExpression('/^OPSDECK_STORAGE_ADDON_MAX_GIB=48$/m', $environmentTemplate);
    }

    public function test_storage_quota_template_matches_the_approved_release_capacity_limits(): void
    {
        $environmentTemplate = file_get_contents(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'.env.example');
        self::assertNotFalse($environmentTemplate);

        self::assertMatchesRegularExpression('/^OPSDECK_STORAGE_QUOTA_FREE_MB=1024$/m', $environmentTemplate);
        self::assertMatchesRegularExpression('/^OPSDECK_STORAGE_QUOTA_PREMIUM_MB=10240$/m', $environmentTemplate);
        self::assertMatchesRegularExpression('/^OPSDECK_PLATFORM_STORAGE_LIMIT_MIB=51200$/m', $environmentTemplate);
    }

    public function test_opsdeck_environment_variables_are_documented_in_the_environment_template(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $configurationFiles = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($projectRoot.DIRECTORY_SEPARATOR.'config'));
        $referencedVariables = [];

        foreach ($configurationFiles as $configurationFile) {
            if (! $configurationFile->isFile() || $configurationFile->getExtension() !== 'php') {
                continue;
            }

            $configuration = file_get_contents($configurationFile->getPathname());
            self::assertNotFalse($configuration);
            preg_match_all("/env\\('(?<name>OPSDECK_[A-Z0-9_]+)'/", $configuration, $matches);
            $referencedVariables = array_merge($referencedVariables, $matches['name']);
        }

        $environmentTemplate = file_get_contents($projectRoot.DIRECTORY_SEPARATOR.'.env.example');
        self::assertNotFalse($environmentTemplate);
        preg_match_all('/^(?<name>OPSDECK_[A-Z0-9_]+)=/m', $environmentTemplate, $documentedMatches);
        $undocumentedVariables = array_values(array_diff(array_unique($referencedVariables), $documentedMatches['name']));
        sort($undocumentedVariables);

        self::assertSame([], $undocumentedVariables, 'Document each OPSDECK_* environment variable in .env.example.');
    }
}
