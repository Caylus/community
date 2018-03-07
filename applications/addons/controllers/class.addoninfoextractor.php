<?php

if (!defined(ADDON_TYPE_GENERAL)) {
    define("ADDON_TYPE_GENERAL",6);
}

class AddonInfoExtractor {

    /**
     * Check an addon's file to extract the addon information out of it.
     *
     * @param string $path The path to the file.
     * @param bool $throwError Whether or not to throw an exception if there is a problem analyzing the addon.
     * @return array An array of addon information.
     */
    public static function analyzeAddon($path, $throwError = true) {
        if (!file_exists($path)) {
            if ($throwError) {
                throw new Exception("$path not found.", 404);
            }
            return false;
        }

        $addon = [];
        $result = [];

        $infoPaths = [
            '/settings/about.php', // application
            '/default.php', // plugin
            '/class.*.php',
            '/class.*.plugin.php', // plugin
            '/about.php', // theme
            '/definitions.php', // locale
            '/environment.php', // vanilla core
            'vanilla2export.php', // porter
            '/addon.json'
        ];

        $entries = self::getInfoZip($path, $infoPaths, false, $throwError);
        $deleteEntries = true;
        if (isset($entries['Result'])) {
            if (empty($entries['Result'])) {
                $addon = val('Addon', $entries);
            }
        } else {

            foreach ($entries as $entry) {
                if ($entry['Name'] == '/environment.php') {
                    // This could be the core vanilla package.
                    $version = UpdateModel::parseCoreVersion($entry['Path']);

                    if (!$version) {
                        continue;
                    }

                    // The application was confirmed.
                    $addon = [
                        'AddonKey' => 'vanilla',
                        'AddonTypeID' => ADDON_TYPE_CORE,
                        'Name' => 'Vanilla',
                        'Description' => 'Vanilla is an open-source, standards-compliant, multi-lingual, fully extensible discussion forum for the web. Anyone who has web-space that meets the requirements can download and use Vanilla for free!',
                        'Version' => $version,
                        'License' => 'GPLv2',
                        'Path' => $entry['Path']];
                    break;
                } elseif ($entry['Name'] == 'vanilla2export.php') {
                    // This could be the vanilla porter.
                    $version = UpdateModel::parseCoreVersion($entry['Path']);

                    if (!$version) {
                        continue;
                    }

                    $addon = [
                        'AddonKey' => 'porter',
                        'AddonTypeID' => ADDON_TYPE_CORE,
                        'Name' => 'Vanilla Porter',
                        'Description' => 'Drop this script in your existing site and navigate to it in your web browser to export your existing forum data to the Vanilla 2 import format.',
                        'Version' => $version,
                        'License' => 'GPLv2',
                        'Path' => $entry['Path']];
                    break;
                } else {
                    // This could be an addon.
                    $info = ($entry['Name'] === '/addon.json') ? self::addonJsonConverter($entry['Path']) : UpdateModel::parseInfoArray($entry['Path']);
                    $result = self::checkAddon($info, $entry);
                    if (!empty($result)) {
                        break;
                    }
                    $addon = self::buildAddon($info);
                }
            }
        }

        if ($deleteEntries) {
            $folderPath = substr($path, 0, -4);
            Gdn_FileSystem::removeFolder($folderPath);
        }

        // Add the addon requirements.
        if (!empty($addon)) {
            $requirements = arrayTranslate(
                    $addon, [
                'RequiredApplications' => 'Applications',
                'RequiredPlugins' => 'Plugins',
                'RequiredThemes' => 'Themes',
                'Require' => 'Addons'
                    ]
            );
            foreach ($requirements as $type => $items) {
                if (!is_array($items)) {
                    unset($requirements[$type]);
                }
            }
            $addon['Requirements'] = dbencode($requirements);

            $addon['Checked'] = true;
            $addon['Path'] = $path;
            $uploadsPath = PATH_UPLOADS . '/';
            if (stringBeginsWith($addon['Path'], $uploadsPath)) {
                $addon['File'] = substr($addon['Path'], strlen($uploadsPath));
            }

            if (is_file($path)) {
                $addon['MD5'] = md5_file($path);
                $addon['FileSize'] = filesize($path);
            }
        } elseif ($throwError) {
            $msg = implode("\n", $result);
            throw new Gdn_UserException($msg, 400);
        } else {
            return false;
        }

        return $addon;
    }

    /**
     * Open a zip archive and inspect its contents for the requested paths.
     * Either returns array of entries to parse, or object from JSON file.
     *
     * @param string $path
     * @param array $infoPaths
     * @param bool $tmpPath
     * @param bool $throwError
     * @return mixed $result
     * @throws Exception
     */
    private static function getInfoZip($path, $infoPaths, $tmpPath = false, $throwError = true) {

        $zip = self::open_zip($path);
        if (!$zip) {
            return [];
        }
        if ($tmpPath === false) {
            $tmpPath = dirname($path) . '/' . basename($path, '.zip') . '/';
        }

        if (file_exists($tmpPath)) {
            Gdn_FileSystem::removeFolder($tmpPath);
        }

        $result = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->statIndex($i);

            if (preg_match('#(\.\.[\\/])#', $entry['name'])) {
                throw new Gdn_UserException("Invalid path in zip file: " . htmlspecialchars($entry['name']));
            }

            $name = '/' . ltrim($entry['name'], '/');

            foreach ($infoPaths as $infoPath) {
                $preg = '`(' . str_replace(['.', '*'], ['\.', '.*'], $infoPath) . ')$`';
                if (preg_match($preg, $name, $matches)) {
                    $base = trim(substr($name, 0, -strlen($matches[1])), '/');

                    if (strpos($base, '/') !== false) {
                        continue; // file nested too deep.
                    }
                    if (!file_exists($tmpPath)) {
                        mkdir($tmpPath, 0777, true);
                    }

                    $zip->extractTo($tmpPath, $entry['name']);
                    if ($infoPath==="/addon.json") {
                        $result= array(['Name' => "/addon.json", 'Path' => $tmpPath . rtrim($entry['name'], '/'), 'Base' => $base]);
                        break;
                    }
                    $result[] = ['Name' => $matches[1], 'Path' => $tmpPath . rtrim($entry['name'], '/'), 'Base' => $base];
                }
            }
        }
        $zip->close();

        return $result;
    }

    /**
     * Coerces an addon.json into something we can check in the update model.
     *
     * @param $path The path to the addon directory
     * @return array The addon info array
     */
    private static function addonJsonConverter($path) {

        $json_file = file_get_contents($path);
        $addonInfo = json_decode($json_file, true);
        if (!$addonInfo) {
            return false;
        }

        $capitalCaseSheme = new \Vanilla\Utility\CapitalCaseScheme();
        $addonInfo=$capitalCaseSheme->convertArrayKeys($addonInfo);
        $slug = trim(substr($path, strrpos($path, '/', -12) + 1,-11));

        $validTypes = ['application', 'plugin', 'theme', 'locale','addon'];

        // If the type is theme or locale then use that.
        $type = val('Type', $addonInfo, 'addon');

        // If oldType is present then use that.
        if (!in_array($type, $validTypes)) {
            $type = val('OldType', $addonInfo, false);
        }

        // If priority is lower than Addon::PRIORITY_PLUGIN then its an application.
        if (!in_array($type, $validTypes) && (val('Priority', $type, Addon::PRIORITY_HIGH) < Addon::PRIORITY_PLUGIN)) {
            $type = 'application';
        }

        // Otherwise, we got a plugin
        if (!in_array($type, $validTypes)) {
            $type = 'plugin';
        }

        $addonInfo['Variable'] = ucfirst($type) . 'Info';
        $info = [$slug => $addonInfo];
        return $info;
    }

    /**
     * Takes an addon's info array and adds extra info to it that is expected by the update model.
     *
     * @param $info The addon info array. The expected format is `addon-key => addon-info`,
     *     where addon-info is the addon's info array.
     * @return array The addon with the extra info included, or an empty array if $info is bad.
     */
    private static function buildAddon($info) {
        if (!is_array($info) && count($info)) {
            return [];
        }

        $key = key($info);
        $info = $info[$key];
        $variable = $info['Variable'];

        $addon = array_merge(['AddonKey' => $key, 'AddonTypeID' => ''], $info);
        $addon['License']='MIT';
        switch ($variable) {
            case 'ApplicationInfo':
                $addon['AddonTypeID'] = ADDON_TYPE_APPLICATION;
                break;
            case 'LocaleInfo':
                $addon['AddonTypeID'] = ADDON_TYPE_LOCALE;
                break;
            case 'PluginInfo':
                $addon['AddonTypeID'] = ADDON_TYPE_PLUGIN;
                break;
            case 'ThemeInfo':
                $addon['AddonTypeID'] = ADDON_TYPE_THEME;
                break;
            default:
                $addon['AddonTypeID'] = ADDON_TYPE_GENERAL;
                break;
        }

        return $addon;
    }

    /**
     * Checks an addon. Returns a collection of errors in an array. If no errors exist, returns an empty array.
     *
     * @param $info The addon info array. The expected format is `addon-key => addon-info`,
     *     where addon-info is the addon's info array.
     * @param $entry Information on where the info was retrieved from. Should include the keys: 'Name' and 'Base',
     *     for the addon name and the addon folder, respectively.
     * @return array The errors with the addon, or an empty array.
     */
    private static function checkAddon($info, $entry) {
        $result = [];

        if (!is_array($info) && count($info)) {
            return ['Could not parse addon info array.'];
        }

        $key = key($info);
        $info = $info[$key];
        $variable = $info['Variable'];

        // Validate the addon.
        $name = $entry['Name'];
        if (!val('Name', $info)) {
            $info['Name'] = $key;
        }

        // Validate basic fields.
        $checkResult = self::checkRequiredFields($info);
        if (count($checkResult)) {
            $result = array_merge($result, $checkResult);
        }

        // Validate folder name matches key.
        if (isset($entry['Base']) && strcasecmp($entry['Base'], $key) != 0 && $variable != 'ThemeInfo') {
            $result[] = "$name: The addon's key is not the same as its folder name.";
        }

        return $result;
    }

    /**
     * Check globally required fields in our addon info.
     *
     * @param $info
     * @return array $results
     */
    protected static function checkRequiredFields(&$info) {
        $results = [];

        if (!val('Description', $info)) {
            $results[] = sprintf(t('ValidateRequired'), t('Description'));
        }

        if (!val('Version', $info)) {
            $results[] = sprintf(t('ValidateRequired'), t('Version'));
        }

        if (!val('License', $info)) {
            $results[] = sprintf(t('ValidateRequired'), t('License'));
        }
        return $results;
    }

    private static function open_zip($path) {
        // Extract the zip file so we can make sure it has appropriate information.
        $zip = null;
        $zipOpened = false;

        if (class_exists('ZipArchive', false)) {
            $zip = new ZipArchive();
            $zipOpened = $zip->open($path);
            if ($zipOpened !== true) {
                $zip = null;
            }
        }

        if (!$zip) {
            $zip = new PclZipAdapter();
            $zipOpened = $zip->open($path);
        }

        if ($zipOpened !== true) {
            if ($throwError) {
                $errors = [ZipArchive::ER_EXISTS => 'ER_EXISTS', ZipArchive::ER_INCONS => 'ER_INCONS', ZipArchive::ER_INVAL => 'ER_INVAL',
                    ZipArchive::ER_MEMORY => 'ER_MEMORY', ZipArchive::ER_NOENT => 'ER_NOENT', ZipArchive::ER_NOZIP => 'ER_NOZIP',
                    ZipArchive::ER_OPEN => 'ER_OPEN', ZipArchive::ER_READ => 'ER_READ', ZipArchive::ER_SEEK => 'ER_SEEK'];
                $error = val($zipOpened, $errors, 'Unknown Error');

                throw new Exception(t('Could not open addon file. Addons must be zip files.') . " ($path $error)", 400);
            }
            return false;
        }
        return $zip;
    }

}
