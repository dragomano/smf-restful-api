<?php

declare(strict_types=1);

use SMF\API\Integration;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

require_once __DIR__ . '/Environment.php';

#[Test]
#[Covers(Integration::class)]
final class IntegrationTest
{
    public function hooksRegistersEveryIntegrationPoint(): void
    {
        Environment::reset();

        (new Integration())->hooks();

        Assert::same($GLOBALS['api_hooks'], [
            ['integrate_autoload', Integration::class . '::autoload#', false],
            ['integrate_actions', Integration::class . '::actions#', false],
            ['integrate_admin_areas', Integration::class . '::adminAreas#', false],
            ['integrate_admin_search', Integration::class . '::adminSearch#', false],
            ['integrate_modify_modifications', Integration::class . '::modifyModifications#', false],
            ['integrate_mdp_create_post', Integration::class . '::suppressPostMerge#', false],
        ]);
    }

    public function autoloadMapsTheNamespaceToItsDirectory(): void
    {
        Environment::reset();
        $classMap = [];

        (new Integration())->autoload($classMap);

        Assert::same($classMap['SMF\\API\\'], 'API/');
    }

    public function actionsRegistersTheApiAction(): void
    {
        Environment::reset();
        $actionArray = [];

        (new Integration())->actions($actionArray);

        Assert::same($actionArray['api'][0], false);
        Assert::instanceOf($actionArray['api'][1][0], Integration::class);
        Assert::same($actionArray['api'][1][1], 'dispatch');
    }

    public function adminAreasAddsTheApiSubsection(): void
    {
        Environment::reset();
        $admin_areas = ['config' => ['areas' => ['modsettings' => ['subsections' => []]]]];

        (new Integration())->adminAreas($admin_areas);

        Assert::same($admin_areas['config']['areas']['modsettings']['subsections']['api'], ['RESTful API']);
        Assert::contains($GLOBALS['api_languages_loaded'], 'API');
    }

    public function adminSearchRegistersTheLanguageFileAndSettingsPage(): void
    {
        Environment::reset();
        $language_files  = [];
        $include_files   = [];
        $settings_search = [];

        (new Integration())->adminSearch($language_files, $include_files, $settings_search);

        Assert::contains($language_files, 'API');
        Assert::instanceOf($settings_search[0][0][0], Integration::class);
        Assert::same($settings_search[0][0][1], 'settings');
        Assert::same($settings_search[0][1], 'area=modsettings;sa=api');
    }

    public function modifyModificationsRegistersTheSettingsSubAction(): void
    {
        Environment::reset();
        $subActions = [];

        (new Integration())->modifyModifications($subActions);

        Assert::instanceOf($subActions['api'][0], Integration::class);
        Assert::same($subActions['api'][1], 'settings');
    }

    public function settingsReturnsTheConfigVarsForTheSearchIndex(): void
    {
        Environment::reset();

        $config = (new Integration())->settings(true);

        Assert::same($config[0][0], 'check');
        Assert::same($config[0][1], 'api_enabled');
    }

    public function settingsBuildsTheEditorContextFromTheStoredKeys(): void
    {
        Environment::reset();
        // A string member id (as JSON may carry) makes the (int) cast observable;
        // two rows guard against the list being truncated.
        $GLOBALS['modSettings']['api_keys'] = '{"token-a":"5","token-b":9}';

        (new Integration())->settings();

        Assert::same($GLOBALS['context']['page_title'], 'RESTful API');
        Assert::same($GLOBALS['context']['settings_title'], 'RESTful API');
        Assert::same($GLOBALS['context']['sub_template'], 'api_settings');
        Assert::same(
            $GLOBALS['context']['post_url'],
            'https://example.test/index.php?action=admin;area=modsettings;save;sa=api'
        );
        Assert::same($GLOBALS['context']['api_keys'], [
            ['token' => 'token-a', 'member' => 5],
            ['token' => 'token-b', 'member' => 9],
        ]);
        Assert::contains($GLOBALS['api_languages_loaded'], 'API');
        Assert::contains($GLOBALS['api_templates_loaded'], 'API');
    }

    public function settingsReturnsAnEmptyKeyListForMalformedStorage(): void
    {
        Environment::reset();
        $GLOBALS['modSettings']['api_keys'] = 'not-json';

        (new Integration())->settings();

        Assert::same($GLOBALS['context']['api_keys'], []);
    }

    public function settingsSavesTheKeyMapAndRedirects(): void
    {
        Environment::reset();
        $_GET['save'] = '';
        // Surrounding whitespace on the token must be trimmed before storage.
        $_POST['api_key_token']  = ['  token-a  '];
        $_POST['api_key_member'] = ['5'];

        $redirected = false;

        try {
            (new Integration())->settings();
        } catch (\RuntimeException $exception) {
            $redirected = true;
            Assert::same($exception->getMessage(), 'redirect:action=admin;area=modsettings;sa=api');
        }

        Assert::true($redirected);
        Assert::true($GLOBALS['api_check_session']);
        Assert::same($GLOBALS['api_updated_settings'], [
            'api_enabled' => 0,
            'api_keys'    => json_encode(['token-a' => 5]),
        ]);
    }

    public function settingsStoresTheEnabledFlagWhenChecked(): void
    {
        Environment::reset();
        $_GET['save']            = '';
        $_POST['api_enabled']    = '1';
        $_POST['api_key_token']  = ['token-a'];
        $_POST['api_key_member'] = ['5'];

        try {
            (new Integration())->settings();
        } catch (\RuntimeException) {
        }

        Assert::same($GLOBALS['api_updated_settings']['api_enabled'], 1);
    }

    public function settingsSkipsAcompletelyBlankKeyRow(): void
    {
        Environment::reset();
        $_GET['save'] = '';
        // A blank row followed by a valid one: the blank is skipped (continue),
        // the valid row still gets processed.
        $_POST['api_key_token']  = ['', 'token-b'];
        $_POST['api_key_member'] = ['', '9'];

        $redirected = false;

        try {
            (new Integration())->settings();
        } catch (\RuntimeException $exception) {
            $redirected = str_starts_with($exception->getMessage(), 'redirect:');
        }

        Assert::true($redirected);
        Assert::same($GLOBALS['api_updated_settings']['api_keys'], json_encode(['token-b' => 9]));
    }

    public function settingsRejectsAkeyRowMissingItsMember(): void
    {
        Environment::reset();
        $_GET['save'] = '';
        $_POST['api_key_token']  = ['token-a'];
        // No member value at all → falls back to the default 0 → rejected.
        $_POST['api_key_member'] = [];

        $caught = null;

        try {
            (new Integration())->settings();
        } catch (\RuntimeException $exception) {
            $caught = $exception->getMessage();
        }

        Assert::same($caught, 'fatal:Invalid API keys');
    }

    public function suppressPostMergeZeroesTheLastMessageForApiRequests(): void
    {
        Environment::reset();

        if (! defined('SMF_API_REQUEST')) {
            define('SMF_API_REQUEST', true);
        }

        $idLastMsg = 4321;
        (new Integration())->suppressPostMerge($idLastMsg);

        Assert::same($idLastMsg, 0);
    }
}
