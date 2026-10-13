<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ConfigurationEnvironmentTemplateTest extends TestCase
{
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
