<?php namespace Waavi\Translation\Commands;

use App;
use DirectoryIterator;
use Exception;
use Illuminate\Console\Command;
use League\Flysystem\File;
use Storage;
use Waavi\Translation\Models\Translation;
use Waavi\Translation\Repositories\LanguageRepository;
use Waavi\Translation\Repositories\TranslationRepository;

class GenerateI18nCommand extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'translator:generate-i18n';
    protected $signature = 'translator:generate-i18n {--umd}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description    = "Generate i18n files";
    private $availableLocales = [];
    private $filesToCreate    = [];

    /**
     *  Create a new mixed loader instance.
     *
     *  @param  \Waavi\Lang\Providers\LanguageProvider        $languageRepository
     *  @param  \Waavi\Lang\Providers\LanguageEntryProvider   $translationRepository
     *  @param  \Illuminate\Foundation\Application            $app
     */
    public function __construct(LanguageRepository $languageRepository, TranslationRepository $translationRepository)
    {
        parent::__construct();
        $this->languageRepository    = $languageRepository;
        $this->translationRepository = $translationRepository;
    }

    /**
     *  Execute the console command.
     *
     *  @return void
     */
    public function fire()
    {
        $root = base_path() . '/resources/lang/js';

        $umd = $this->option('umd');

        $this->exportJsTranslations();
        $files = $this->generateMultiple($root, $umd);
    }

    public function exportJsTranslations()
    {
        $jsTranslations = Translation::where("namespace", "js")->get();
        $tree           = $this->makeTree($jsTranslations);
        foreach ($tree as $locale => $groups) {
            foreach ($groups as $key => $group) {
                $jsTranslations = $group;
                $path           = resource_path() . '/lang/js/' . $locale . '/' . $key . '.php';
                $output         = "<?php\n\nreturn " . var_export($jsTranslations, true) . ";\n";
                if(!file_exists(resource_path() . '/lang/js/' . $locale)) {
                    mkdir(resource_path() . '/lang/js/' . $locale);
                }
                if (!file_exists($path)) {
                    fopen($path, 'w');
                }

                file_put_contents($path, $output);
            }
        }
    }

    protected function makeTree($translations)
    {
        $array = array();
        foreach ($translations as $translation) {
            array_set($array[$translation->locale][$translation->group], $translation->item, $translation->text);
        }
        return $array;
    }

    /**
     * @param string $path
     * @return string
     * @throws Exception
     */
    public function generateFromPath($path)
    {
        if (!is_dir($path)) {
            throw new Exception('Directory not found: ' . $path);
        }

        $locales = [];
        $dir     = new DirectoryIterator($path);
        $jsBody  = '';
        foreach ($dir as $fileinfo) {
            if (!$fileinfo->isDot()
                && !in_array($fileinfo->getFilename(), ['vendor'])
            ) {
                $noExt = $this->removeExtension($fileinfo->getFilename());

                if ($fileinfo->isDir()) {
                    $local = $this->allocateLocaleArray($fileinfo->getRealPath());
                } else {
                    $local = $this->allocateLocaleJSON($fileinfo->getRealPath());
                    if ($local === null) {
                        continue;
                    }

                }

                if (isset($locales[$noExt])) {
                    $locales[$noExt] = array_merge($local, $locales[$noExt]);
                } else {
                    $locales[$noExt] = $local;
                }

            }
        }

        $jsonLocales = json_encode($locales, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;

        $jsBody = $this->getES6Module($jsonLocales);
        return $jsBody;
    }

    /**
     * @param string $path
     * @param boolean $umd
     * @return string
     * @throws Exception
     */
    public function generateMultiple($path, $umd = null)
    {
        if (!is_dir($path)) {
            throw new Exception('Directory not found: ' . $path);
        }
        $jsPath       = base_path() . '/resources/assets/js/langs/';
        $locales      = [];
        $fileToCreate = '';
        $createdFiles = '';
        $dir          = new DirectoryIterator($path);
        $jsBody       = '';
        foreach ($dir as $fileinfo) {
            if (!$fileinfo->isDot()
                && !in_array($fileinfo->getFilename(), ['vendor'])
            ) {
                $noExt = $this->removeExtension($fileinfo->getFilename());
                if (!in_array($noExt, $this->availableLocales)) {
                    App::setLocale($noExt);
                    $this->availableLocales[] = $noExt;
                }
                if ($fileinfo->isDir()) {
                    $local = $this->allocateLocaleArray($fileinfo->getRealPath());
                } else {
                    $local = $this->allocateLocaleJSON($fileinfo->getRealPath());
                    if ($local === null) {
                        continue;
                    }

                }

                if (isset($locales[$noExt])) {
                    $locales[$noExt] = array_merge($local, $locales[$noExt]);
                } else {
                    $locales[$noExt] = $local;
                }

            }
        }
        foreach ($this->filesToCreate as $fileName => $data) {
            $fileToCreate = $jsPath . $fileName . '.js';
            $createdFiles .= $fileToCreate . PHP_EOL;
            $jsonLocales = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;

            if (!$umd) {
                $jsBody = $this->getES6Module($jsonLocales);
            } else {
                $jsBody = $this->getUMDModule($jsonLocales);
            }

            if (!is_dir(dirname($fileToCreate))) {
                mkdir(dirname($fileToCreate), 0777, true);
            }

            Storage::disk('s3')->put("js-translations/" . $fileName . '.js', $jsBody, 'public');
        }
        return $createdFiles;
    }

    /**
     * @param string $path
     * @return array
     */
    private function allocateLocaleJSON($path)
    {
        // Ignore non *.json files (ex.: .gitignore, vim swap files etc.)
        if (pathinfo($path, PATHINFO_EXTENSION) !== 'json') {
            return null;
        }
        $tmp = (array) json_decode(file_get_contents($path), true);
        if (gettype($tmp) !== "array") {
            throw new Exception('Unexpected data while processing ' . $path);
        }

        return $this->adjustArray($tmp);
    }

    /**
     * @param string $path
     * @return array
     */
    private function allocateLocaleArray($path)
    {
        $data       = [];
        $dir        = new DirectoryIterator($path);
        $lastLocale = last($this->availableLocales);
        foreach ($dir as $fileinfo) {
            // Do not mess with dotfiles at all.
            if ($fileinfo->isDot()) {
                continue;
            }

            if ($fileinfo->isDir()) {
                // Recursivley iterate through subdirs, until everything is allocated.

                $data[$fileinfo->getFilename()] =
                $this->allocateLocaleArray($path . '/' . $fileinfo->getFilename());
            } else {
                $noExt    = $this->removeExtension($fileinfo->getFilename());
                $fileName = $path . '/' . $fileinfo->getFilename();

                // Ignore non *.php files (ex.: .gitignore, vim swap files etc.)
                if (pathinfo($fileName, PATHINFO_EXTENSION) !== 'php') {
                    continue;
                }

                $tmp = include $fileName;

                if (gettype($tmp) !== "array") {
                    throw new Exception('Unexpected data while processing ' . $fileName);
                    continue;
                }
                if ($lastLocale !== false) {
                    $root                                        = realpath(base_path() . '/resources/lang/js' . '/' . $lastLocale);
                    $filePath                                    = $this->removeExtension(str_replace('\\', '_', ltrim(str_replace($root, '', realpath($fileName)), '\\')));
                    $this->filesToCreate[$filePath][$lastLocale] = $this->adjustArray($tmp);
                }

                $data[$noExt] = $this->adjustArray($tmp);

            }
        }
        return $data;
    }

    /**
     * @param array $arr
     * @return array
     */
    private function adjustArray(array $arr)
    {
        $res = [];
        foreach ($arr as $key => $val) {

            if (is_string($val)) {
                $res[$key] = $this->adjustString($val);
            } else {
                $res[$key] = $this->adjustArray($val);
            }
        }
        return $res;
    }

    /**
     * Turn Laravel style ":link" into vue-i18n style "{link}"
     * @param string $s
     * @return string
     */
    private function adjustString($s)
    {
        if (!is_string($s)) {
            return $s;
        }

        return preg_replace_callback(
            '/(?<!mailto|tel):\w+/',
            function ($matches) {
                return '{' . mb_substr($matches[0], 1) . '}';
            },
            $s
        );
    }

    /**
     * Returns filename, with extension stripped
     * @param string $filename
     * @return string
     */
    private function removeExtension($filename)
    {
        $pos = mb_strrpos($filename, '.');
        if ($pos === false) {
            return $filename;
        }

        return mb_substr($filename, 0, $pos);
    }

    /**
     * Returns an UMD style module
     * @param string $body
     * @return string
     */
    private function getUMDModule($body)
    {
        $js = <<<HEREDOC
(function (global, factory) {
    typeof exports === 'object' && typeof module !== 'undefined' ? module.exports = factory() :
        typeof define === 'function' && define.amd ? define(factory) :
            (global.vuei18nLocales = factory());
}(this, (function () { 'use strict';
    return {$body}
})));
HEREDOC;
        return $js;
    }

    /**
     * Returns an ES6 style module
     * @param string $body
     * @return string
     */
    private function getES6Module($body)
    {
        return "export default {$body}";
    }
}
