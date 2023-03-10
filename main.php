<?php
declare(strict_types=1);

/**
 * @property-read string $gunLua
 * @property-read string $skinLua
 * @property-read string $live2dPackInput
 * @property-read string $live2dPackOutput
 * @property-read string $extractedLive2DJson
 * @property-read string $live2dHashJson
 * @property-read string $live2dExtractor
 * @property-read string $live2dExtractOutput
 * @property-read string $live2dResourcePath
 * @property-read string $avatarInput
 * @property-read string $avatarResourcePath
 * @property-read string $archivedAvatarJson
 * @property-read string $imageInput
 * @property-read string $imageResourcePath
 * @property-read string $archivedImageJson
 * @property-read string $spineInput
 * @property-read string $spineResourcePath
 * @property-read string $characterDataJson
 * @property-read array $specialGunCode
 */
class ScriptConfig {
    private array $config;
    public function __construct(array $config)
    {
        $this->config = $config;
    }
    
    public function __get($name)
    {
        return $this->config[$name];
    }
}

class GFScript 
{
    const CONFIG_PATH = __DIR__ . DIRECTORY_SEPARATOR . 'config.json';

    private ScriptConfig $config;

    private array $guns = [];
    private array $skins = [];
    private array $extractedLive2D = [];
    private array $live2dHash = [];
    private array $archivedAvatars = [];
    private array $archivedImages = [];

    public function __construct()
    {
        $this->config = new ScriptConfig(json_decode(file_get_contents(self::CONFIG_PATH), true));
        $this->loadLua('guns', $this->config->gunLua, []);
        $this->loadLua('skins', $this->config->skinLua, []);
        $this->load('extractedLive2D', $this->config->extractedLive2DJson, []);
        $this->load('live2dHash', $this->config->live2dHashJson, []);
        $this->load('archivedAvatars', $this->config->archivedAvatarJson, []);
        $this->load('archivedImages', $this->config->archivedImageJson, []);
    }

    public function __destruct()
    {
        $this->store($this->extractedLive2D, $this->config->extractedLive2DJson);
        $this->store($this->live2dHash, $this->config->live2dHashJson);
        $this->store($this->archivedAvatars, $this->config->archivedAvatarJson);
        $this->store($this->archivedImages, $this->config->archivedImageJson, []);
    }

    private function load(string $name, string $input, $default = null) : void
    {
        if(is_file($input)) {
            $this->{$name} = json_decode(file_get_contents($input), true);
        } else {
            $this->{$name} = $default;
        }
    }

    private function loadLua(string $name, string $input, $default = null) : void
    {
        if(is_file($input)) {
            $this->{$name} = (new Lua())->include($input);
        } else {
            $this->{$name} = $default;
        }
    }

    private function store(array $data, string $output) : void
    {
        $jsonStr = json_encode($data, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
        file_put_contents($output, $jsonStr);
    }

    private function output(string $content, bool $eol = true) : void
    {
        echo $content, $eol ? PHP_EOL : '';
    }

    private function execute(string $command, ?Closure $callback = null) : void
    {
        $ch = popen($command, 'w');
        while (!feof($ch)) {
            $output = fgets($ch);
            if($output) {
                $this->output($output, false);
            }
        }
        if($callback !== null) {
            $callback($ch);
        } 
        pclose($ch);
    }

    private function copyDir(string $from, string $to) : void
    {
        $command = implode(' ', [
            'xcopy', 
            str_replace(['/', '\\'], DIRECTORY_SEPARATOR ,$from), 
            str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $to), 
            '/Y', 
            '/E', 
            '/I', 
            '/Q',
            '/D',
        ]);
        $this->execute($command);
    }

    private function rmdir(string $dirpath) : void
    {
        $command = implode(' ', ['rmdir', str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $dirpath), '/S', '/Q', ]);
        $this->execute($command);
    }
    
    public function archiveLive2D() : void
    {
        $pattern = '/live2dnewgun(.*)\.ab/';
        $diritems = scandir($this->config->live2dPackInput);
        foreach($diritems as $diritem) {
            if($diritem === '.' || $diritem === '..') continue;
            $src = $this->config->live2dPackInput . DIRECTORY_SEPARATOR . $diritem;
            $hash = md5_file($src);
            if(($this->live2dHash[$diritem] ?? '') === $hash && in_array($diritem, $this->extractedLive2D)) {
                continue;
            }
            if(preg_match($pattern, $diritem, $matches)) {
                $code = $matches[1];
                $outputDir = $this->config->live2dPackOutput . DIRECTORY_SEPARATOR . $code;
                if(!is_dir($outputDir)) {
                    mkdir($outputDir, 0777, true);
                }
                $dst = $this->config->live2dPackOutput . DIRECTORY_SEPARATOR . $code . DIRECTORY_SEPARATOR . $code . '.ab';
                copy($src, $dst);
                $this->extractLive2D($outputDir);
                $this->extractedLive2D[] = $diritem;
                $this->live2dHash[$diritem] = $hash;
            }
        }

        if(!is_dir($this->config->live2dResourcePath)) {
            mkdir($this->config->live2dResourcePath, 0777, true);
        }

        $diritems = scandir($this->config->live2dExtractOutput);
        foreach($diritems as $diritem) {
            if($diritem === '.' || $diritem === '..') continue;
            $src = $this->config->live2dExtractOutput . DIRECTORY_SEPARATOR . $diritem;
            $dst = $this->config->live2dResourcePath . DIRECTORY_SEPARATOR . $diritem;
            
            $normalJSON = $src . DIRECTORY_SEPARATOR . 'normal' . DIRECTORY_SEPARATOR . 'normal.model3.json';
            $normalModel = json_decode(file_get_contents($normalJSON), true);
            $normalMotions = $normalModel['FileReferences']['Motions'][''] ?? [];
            foreach($normalMotions as $normalMotion) {
                $parts = explode('/', $normalMotion['File']);
                $motionJsonName = end($parts);
                $motionGroup = $this->getMotionGroupFromMotionFilename($motionJsonName);
                $motionGroup = stripos($motionGroup, 'daiji_idle') === 0 ? 'Idle' : $motionGroup;
                if(!isset($normalModel['FileReferences']['Motions'][ucfirst($motionGroup)])) {
                    $normalModel['FileReferences']['Motions'][ucfirst($motionGroup)] = [];
                }
                $normalMotion['FadeInTime'] = 0.5;
                $normalMotion['FadeOutTime'] = 0.5;
                $normalModel['FileReferences']['Motions'][ucfirst($motionGroup)][] = $normalMotion;
            }
            unset($normalModel['FileReferences']['Motions']['']);
            $this->store($normalModel, $normalJSON);

            $destroyJSON = $src . DIRECTORY_SEPARATOR . 'destroy' . DIRECTORY_SEPARATOR . 'destroy.model3.json';
            $destroyModel = json_decode(file_get_contents($destroyJSON), true);
            $destroyMotions = $destroyModel['FileReferences']['Motions'][''] ?? [];
            foreach($destroyMotions as $destroyMotion) {
                $parts = explode('/', $destroyMotion['File']);
                $motionJsonName = end($parts);
                $motionGroup = $this->getMotionGroupFromMotionFilename($motionJsonName);
                $motionGroup = stripos($motionGroup, 'daiji_idle') === 0 ? 'Idle' : $motionGroup;
                if(!isset($destroyModel['FileReferences']['Motions'][ucfirst($motionGroup)])) {
                    $destroyModel['FileReferences']['Motions'][ucfirst($motionGroup)] = [];
                }
                $destroyModel['FadeInTime'] = 0.5;
                $destroyModel['FadeOutTime'] = 0.5;
                $destroyModel['FileReferences']['Motions'][ucfirst($motionGroup)][] = $destroyMotion;
            }
            unset($destroyModel['FileReferences']['Motions']['']);
            $this->store($destroyModel, $destroyJSON);

            rename($src, $dst);
        }
    }

    public function extractLive2D($live2d) : void
    {
        $command = $this->config->live2dExtractor;
        $command .= ' ' . $live2d;
        $this->execute($command, function($ch) {
            fwrite($ch, PHP_EOL);
        });
    }

    private function getMotionGroupFromMotionFilename(string $filename) : string
    {
        $parts = explode('_', $filename);
        array_pop($parts);
        return implode('_', $parts);
    }

    public function archiveAvatar() : void
    {
        $diritems = scandir($this->config->avatarInput);
        foreach($diritems as $diritem) {
            if($diritem === '.' || $diritem === '..') continue;
            if(in_array($diritem, $this->archivedAvatars)) continue;
            $picPath = $this->config->avatarInput . DIRECTORY_SEPARATOR . $diritem . DIRECTORY_SEPARATOR . 'pic';
            if(!is_dir($picPath)) continue;
            $pics = scandir($picPath);
            foreach($pics as $pic) {
                if($pic === '.' || $pic === '..') continue;
                if(!preg_match('/.*_N.png$/', $pic)) continue;
                $imagePath = $picPath . DIRECTORY_SEPARATOR . $pic;
                $image = imagecreatefrompng($imagePath);
                $width = imagesx($image);
                $height = imagesy($image);
                $normalAvatar = imagecreatetruecolor($width/2, $height);
                $brokenAvatar = imagecreatetruecolor($width/2, $height);
                imagecopy($normalAvatar, $image, 0, 0, 0, 0, $width, $height);
                imagecopy($brokenAvatar, $image, 0, 0, $width/2, 0, $width, $height);
                $outputDirpath = $this->config->avatarResourcePath . DIRECTORY_SEPARATOR . $diritem;
                if(!is_dir($outputDirpath)) {
                    mkdir($outputDirpath, 0777, true);
                }
                imagepng($normalAvatar, $outputDirpath . DIRECTORY_SEPARATOR . 'normal.png');
                imagepng($brokenAvatar, $outputDirpath . DIRECTORY_SEPARATOR . 'broken.png');
            }
            $this->archivedAvatars[] = $diritem;
        }
    }

    public function archivePaintings() : void
    {
        $diritems = scandir($this->config->imageInput);
        foreach($diritems as $diritem) {
            if($diritem === '.' || $diritem === '..') continue;
            if(in_array($diritem, $this->archivedImages)) continue;
            $images = scandir($this->config->imageInput . DIRECTORY_SEPARATOR . $diritem);
            foreach($images as $image) {
                if($image === '.' || $image === '..') continue;
                if(preg_match('/.*_Alpha.png/', $image)) continue;
                $imagePath = $this->config->imageInput . DIRECTORY_SEPARATOR . $diritem . DIRECTORY_SEPARATOR . $image;
                $alphaPath = $this->config->imageInput . DIRECTORY_SEPARATOR . $diritem . DIRECTORY_SEPARATOR . str_replace('.png', '_Alpha.png', $image);
                if(!is_file($alphaPath)) continue;
                $alphaImage = imagecreatefrompng($alphaPath);
                $alphaImage = imagescale($alphaImage, 2048, 2048, IMG_BICUBIC_FIXED);
                $paintingImage = imagecreatefrompng($imagePath);
                $paintingImage = imagescale($paintingImage, 2048, 2048, IMG_BICUBIC_FIXED);
                $canvas = imagecreatetruecolor(2048, 2048);
                $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
                imagefill($canvas, 0, 0, $transparent);
                for($x = 0; $x < 2048; $x++) {
                    for($y = 0; $y < 2048; $y++) {
                        $alphaColor = imagecolorat($alphaImage, $x, $y);
                        $alphaRgba = imagecolorsforindex($alphaImage, $alphaColor);
                        $alpha = $alphaRgba['alpha'];

                        $paintingColor = imagecolorat($paintingImage, $x, $y);
                        $paintingRgba = imagecolorsforindex($paintingImage, $paintingColor);

                        $color = imagecolorallocatealpha($canvas, $paintingRgba['red'], $paintingRgba['green'], $paintingRgba['blue'], $alpha);
                        imagesetpixel($canvas, $x, $y, $color);
                    }
                }
                $outputDirpath = $this->config->imageResourcePath . DIRECTORY_SEPARATOR . $diritem;
                if(!is_dir($outputDirpath)) {
                    mkdir($outputDirpath, 0777, true);
                }
                imagesavealpha($canvas, true);
                imagepng($canvas, $outputDirpath . DIRECTORY_SEPARATOR . $image, 9);
                imagedestroy($canvas);
                imagedestroy($alphaImage);
                imagedestroy($paintingImage);
            }
            $this->archivedImages[] = $diritem;
        }
    }

    public function archiveSpines() : void
    {
        $diritems = scandir($this->config->spineInput);
        foreach($diritems as $diritem) {
            if($diritem === '.' || $diritem === '..') continue;
            $spineDirpath = $this->config->spineInput . DIRECTORY_SEPARATOR . $diritem . DIRECTORY_SEPARATOR . 'spine';
            if(!is_dir($spineDirpath)) continue;
            $spineResourceDirpath = $this->config->spineResourcePath . DIRECTORY_SEPARATOR . $diritem;
            if(!is_dir($spineResourceDirpath)) {
                mkdir($spineResourceDirpath, 0777, true);
            }
            $spineFiles = scandir($spineDirpath);
            foreach($spineFiles as $spineFile) {
                if($spineFile === '.' || $spineFile === '..') continue;
                $spineFilepath = $spineDirpath . DIRECTORY_SEPARATOR . $spineFile;
                if(is_dir($spineFilepath)) continue;
                $ext = pathinfo($spineFilepath, PATHINFO_EXTENSION);
                if ($ext === 'asset') {
                    $filename = pathinfo($spineFilepath, PATHINFO_FILENAME);
                    $spineFileResouorcePath = $spineResourceDirpath . DIRECTORY_SEPARATOR . $filename;
                    if(is_file($spineFileResouorcePath)) {
                        unlink($spineFileResouorcePath);
                    }
                    copy($spineFilepath, $spineFileResouorcePath);
                } else {
                    $spineFileResouorcePath = $spineResourceDirpath . DIRECTORY_SEPARATOR . $spineFile;
                    if(is_file($spineFileResouorcePath)) {
                        unlink($spineFileResouorcePath);
                    }
                    copy($spineFilepath, $spineFileResouorcePath);
                }
            }
        }
    }

    private function getAvatars(string $code) : array
    {
        $originalCode = $code;
        $code = $this->config->specialGunCode[$code] ?? strtolower($code);
        $normalAvatar = implode('/', [$code, 'normal.png']);
        $normalAvatarPath = $this->config->avatarResourcePath . DIRECTORY_SEPARATOR . strtolower($code) . DIRECTORY_SEPARATOR . 'normal.png';
        if(!is_file($normalAvatarPath)) {
            throw new Exception($originalCode . ' normal avatar not exists');
        }
        $brokenAvatar = implode('/', [$code, 'broken.png']);
        $brokenAvatarPath = $this->config->avatarResourcePath . DIRECTORY_SEPARATOR . strtolower($code) . DIRECTORY_SEPARATOR . 'broken.png';
        if(!is_file($brokenAvatarPath)) {
            throw new Exception($originalCode . ' broken avatar not exists');
        }
        return [
            'normal' => $normalAvatar,
            'destroy' => $brokenAvatar,
        ];
    }

    public function getPaintings(string $code) : array
    {
        $originalCode = $code;
        $code = $this->config->specialGunCode[$code] ?? strtolower($code);
        $normalImageResourcePath = $this->config->imageResourcePath . DIRECTORY_SEPARATOR . $code . DIRECTORY_SEPARATOR . "pic_{$code}_HD.png";
        if(!is_file($normalImageResourcePath)) {
            throw new Exception($originalCode . ' normal image not exists');
        }
        $normalImage = $code . '/' . basename(realpath($normalImageResourcePath));

        $brokenImageResourcePath = $this->config->imageResourcePath . DIRECTORY_SEPARATOR . $code . DIRECTORY_SEPARATOR . "pic_{$code}_HD.png";
        if(!is_file($brokenImageResourcePath)) {
            throw new Exception($originalCode . ' broken image not exists');
        }
        $brokenImage = $code . '/' . basename(realpath($brokenImageResourcePath));
        return [
            'normal' => $normalImage,
            'destroy' => $brokenImage,
        ];
    }

    public function getLive2D(string $code) : array
    {
        $code = $this->config->specialGunCode[$code] ?? strtolower($code);
        $live2d = [];
        $normalLive2DModelPath = $this->config->live2dResourcePath . DIRECTORY_SEPARATOR . $code . DIRECTORY_SEPARATOR . 'normal' . DIRECTORY_SEPARATOR . 'normal.model3.json';
        if(is_file($normalLive2DModelPath)) {
            $live2d['normal'] = "$code/normal/normal.model3.json";
        }
        $brokenLive2DModelPath = $this->config->live2dResourcePath . DIRECTORY_SEPARATOR . $code . DIRECTORY_SEPARATOR . 'destroy' . DIRECTORY_SEPARATOR . 'destroy.model3.json';
        if(is_file($brokenLive2DModelPath)) {
            $live2d['destroy'] = "$code/destroy/destroy.model3.json";
        }
        return $live2d;
    }

    public function getSpine(string $code) : array
    {
        $code = $this->config->specialGunCode[$code] ?? strtolower($code);
        $spine = [];
        $spineDirpath = $this->config->spineResourcePath . DIRECTORY_SEPARATOR . $code;
        $normalSpineSkelPath = $spineDirpath . DIRECTORY_SEPARATOR . "{$code}.skel";
        $normalSpineAtlasPath = $spineDirpath . DIRECTORY_SEPARATOR . "{$code}.atlas";
        if(is_file($normalSpineSkelPath) && is_file($normalSpineAtlasPath)) {
            $normalSkel = $code . '/' . basename(realpath($normalSpineSkelPath));
            $normalAtlas = $code . '/' . basename(realpath($normalSpineAtlasPath));
            $spine['normal'] = [
                'skel' => $normalSkel,
                'atlas' => $normalAtlas,
            ];
        }

        $restSpineSkelPath = $spineDirpath . DIRECTORY_SEPARATOR . "{$code}.skel";
        if(!is_file($restSpineSkelPath)) {
            $restSpineSkelPath = $normalSpineSkelPath;
        }
        $restSpineAtlasPath = $spineDirpath . DIRECTORY_SEPARATOR . "{$code}.atlas";
        if(is_file($restSpineSkelPath) && is_file($restSpineAtlasPath)) {
            $restSkel = $code . '/' . basename(realpath($restSpineSkelPath));
            $restAtlas = $code . '/' . basename(realpath($restSpineAtlasPath));
            $spine['rest'] = [
                'skel' => $restSkel,
                'atlas' => $restAtlas,
            ];
        }

        return $spine;
    }

    public function generateCharacterData() : void
    {
        $characters = [];
        foreach($this->guns as $gun) {
            if(!isset($gun['code'])) continue;
            try {
                $code = $gun['code'];
                $character = [
                    'code' => $code,
                    'name' => $gun['name'],
                    'avatar' => $this->getAvatars($code),
                    'rank' => $gun['rank'] ?? null,
                    'type' => $gun['type'] ?? null,
                    'launch_time' => $gun['launch_time'],
                    'skins' => [],
                ];
                $defaultSkin = [
                    'code' => $code,
                    'name' => '默认立绘',
                    'avatar' => $this->getAvatars($code),
                    'image' => $this->getPaintings($code),
                    'class' => 0,
                ];
                $live2d = $this->getLive2D($code);
                if(!empty($live2d)) {
                    $defaultSkin['live2d'] = $live2d;
                }
                $spine = $this->getSpine($code);
                if(!empty($spine)) {
                    $defaultSkin['spine'] = $spine;
                }
                $character['skins'][] = $defaultSkin;
                $characters[strtoupper($this->config->specialGunCode[$code] ?? $code)] = $character;
            } catch(Throwable $e) {
                $this->output($e->getMessage());
            }
        }

        $avatarItems = scandir($this->config->avatarResourcePath);

        foreach($characters as $code => $character) {
            $validCodes = preg_grep("/^$code(_|mod)/i", $avatarItems);
            foreach($validCodes as $validCode) {
                $skinCode = trim(str_ireplace($code, '', $validCode), '_');
                $skinInfo = $this->skins[$skinCode] ?? [];
                try {
                    $skin = [
                        'code' => $validCode,
                        'name' => $skinCode === 'mod' ? '心智升级' : $skinInfo['name'] ?? ucfirst($validCode),
                        'avatar' => $this->getAvatars($validCode),
                        'image' => $this->getPaintings($validCode),
                        'class' => $skinInfo['class'] ?? ($skinCode === 'mod' ? 99 : -1),
                    ];
                    $dialog = $skinInfo['dialog'] ?? '';
                    if($dialog) {
                        $skin['dialog'] = $dialog;
                    }
                    $note = $skinInfo['note'] ?? '';
                    if($note) {
                        $skin['note'] = $note;
                    }
                    $live2d = $this->getLive2D($validCode);
                    if(!empty($live2d)) {
                        $skin['live2d'] = $live2d;
                    }
                    $spine = $this->getSpine($validCode);
                    if(!empty($spine)) {
                        $skin['spine'] = $spine;
                    }
                    $character['skins'][] = $skin;
                } catch(Throwable $e) {
                    $this->output($e->getMessage());
                    continue;
                }
            }

            $characters[$code] = $character;
        }
        
        $this->store(array_values($characters), $this->config->characterDataJson);
    }
}

function main() 
{
    if(version_compare(PHP_VERSION, '7.4', '<')) {
        echo 'php version \'^7.4\' is required', PHP_EOL;
        exit;
    }
    if(!extension_loaded('lua')) {
        echo 'extension \'lua\' is required', PHP_EOL;
        exit;
    }
    try {
        $script = new GFScript();
        $script->archiveLive2D();
        $script->archiveAvatar();
        $script->archivePaintings();
        $script->archiveSpines();
        $script->generateCharacterData();
    } catch(Throwable $e) {
        echo $e->getMessage(), PHP_EOL;
    }
}

main();