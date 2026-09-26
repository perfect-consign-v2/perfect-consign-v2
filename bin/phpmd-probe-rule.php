<?php

declare(strict_types=1);

/**
 * Probe a single PHPMD rule against src/ without modifying phpmd.xml.
 *
 * Usage:
 *   php bin/phpmd-probe-rule.php rulesets/design.xml/GotoStatement
 *   php bin/phpmd-probe-rule.php rulesets/codesize.xml/ExcessiveMethodLength minimum=175
 *   php bin/phpmd-probe-rule.php rulesets/design.xml/CouplingBetweenObjects
 *   php bin/phpmd-probe-rule.php rulesets/codesize.xml/CyclomaticComplexity reportLevel=15
 *
 * Exits 0 when there are no violations; 1 when violations exist; 2 on usage or tooling errors.
 */

$root = dirname(__DIR__);
chdir($root);

if (!isset($argv[1]) || $argv[1] === '-h' || $argv[1] === '--help') {
    fwrite(STDERR, "Usage: php bin/phpmd-probe-rule.php <rule-ref> [name=value ...]\n");
    fwrite(STDERR, "Example: php bin/phpmd-probe-rule.php rulesets/design.xml/GotoStatement\n");
    exit(2);
}

$ruleRef = $argv[1];
if (!preg_match('#^rulesets/[a-z]+\.xml/[A-Za-z0-9]+$#', $ruleRef)) {
    fwrite(STDERR, "Invalid rule ref \"{$ruleRef}\". Expected rulesets/<file>.xml/<RuleName>.\n");
    exit(2);
}

$properties = [];
for ($i = 2, $n = count($argv); $i < $n; $i++) {
    $arg = $argv[$i];
    if (!str_contains($arg, '=')) {
        fwrite(STDERR, "Invalid property \"{$arg}\". Expected name=value.\n");
        exit(2);
    }
    [$name, $value] = explode('=', $arg, 2);
    if ($name === '' || $value === '') {
        fwrite(STDERR, "Invalid property \"{$arg}\". Expected name=value.\n");
        exit(2);
    }
    $properties[$name] = $value;
}

$phpmdBin = $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'phpmd';
if (!is_file($phpmdBin)) {
    fwrite(STDERR, "PHPMD not found at {$phpmdBin}. Run composer install first.\n");
    exit(2);
}

$propertiesXml = '';
foreach ($properties as $name => $value) {
    $propertiesXml .= '            <property name="' . htmlspecialchars($name, ENT_XML1) . '" value="'
        . htmlspecialchars($value, ENT_XML1) . '"/>' . "\n";
}

$rulesetBody = "    <rule ref=\"{$ruleRef}\">\n";
if ($propertiesXml !== '') {
    $rulesetBody .= "        <properties>\n{$propertiesXml}        </properties>\n";
}
$rulesetBody .= "    </rule>\n";

$excludePatterns = [
    '*/vendor/*',
    '*/tests/*',
    '*/storage/*',
];
$excludeXml = '';
foreach ($excludePatterns as $pattern) {
    $excludeXml .= '    <exclude-pattern>' . htmlspecialchars($pattern, ENT_XML1) . "</exclude-pattern>\n";
}

$rulesetXml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<ruleset name="phpmd-probe"
    xmlns="http://pmd.sf.net/ruleset/1.0.0"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xsi:schemaLocation="http://pmd.sf.net/ruleset/1.0.0 http://pmd.sf.net/ruleset_xml_schema.xsd"
    xsi:noNamespaceSchemaLocation="http://pmd.sf.net/ruleset_xml_schema.xsd">
{$excludeXml}{$rulesetBody}</ruleset>

XML;

$tempPath = tempnam(sys_get_temp_dir(), 'phpmd-probe-');
if ($tempPath === false) {
    fwrite(STDERR, "Could not create temporary ruleset file.\n");
    exit(2);
}

$rulesetPath = $tempPath . '.xml';
if (!rename($tempPath, $rulesetPath)) {
    unlink($tempPath);
    fwrite(STDERR, "Could not finalize temporary ruleset file.\n");
    exit(2);
}

$written = file_put_contents($rulesetPath, $rulesetXml);
if ($written === false) {
    unlink($rulesetPath);
    fwrite(STDERR, "Could not write temporary ruleset file.\n");
    exit(2);
}

$phpBinary = PHP_BINARY;
$errorReporting = 'E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED';
$command = escapeshellarg($phpBinary)
    . ' -d error_reporting=' . escapeshellarg($errorReporting)
    . ' -d display_errors=0'
    . ' ' . escapeshellarg($phpmdBin)
    . ' ' . escapeshellarg('src')
    . ' ' . escapeshellarg('text')
    . ' ' . escapeshellarg($rulesetPath);

$output = [];
$exitCode = 0;
exec($command . ' 2>&1', $output, $exitCode);
unlink($rulesetPath);

$violations = [];
foreach ($output as $line) {
    if (preg_match('/\.php:\d+/', $line) === 1) {
        $violations[] = $line;
    }
}

echo 'Rule: ' . $ruleRef . "\n";
if ($properties !== []) {
    echo 'Properties: ' . json_encode($properties, JSON_THROW_ON_ERROR) . "\n";
}
echo 'Violations: ' . count($violations) . "\n";

if ($violations === []) {
    if ($exitCode !== 0) {
        foreach ($output as $line) {
            fwrite(STDERR, $line . "\n");
        }
        exit(2);
    }
    exit(0);
}

foreach ($violations as $line) {
    echo $line . "\n";
}

exit(1);
