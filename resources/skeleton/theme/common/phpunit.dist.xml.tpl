<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/13.4/phpunit.xsd"
         bootstrap="tests/bootstrap.php"
         cacheDirectory=".phpunit.cache"
         colors="true"
         failOnDeprecation="true"
         failOnNotice="true"
         failOnWarning="true"
>
    <php>
        <!-- Used unless DATABASE_URL is already set; "_test" is added to the database name. -->
        <env name="DATABASE_URL" value="postgresql://app:!ChangeMe!@database:5432/app?serverVersion=18"/>
        <!-- Keeps the log of console commands that tests run out of the test output. -->
        <server name="SHELL_VERBOSITY" value="-1"/>
    </php>

    <testsuites>
        <testsuite name="@@TITLE@@">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
</phpunit>
