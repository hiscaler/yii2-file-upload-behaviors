<?php

namespace yadjet\behaviors;

use yadjet\helpers\ImageHelper;
use yadjet\helpers\IsHelper;
use yadjet\helpers\StringHelper;
use Yii;
use yii\base\Behavior;
use yii\db\ActiveRecord;
use yii\imagine\Image;
use yii\web\UploadedFile;

/**
 * Image uploaded behavior class.
 *
 * @author hiscaler <hiscaler@gmail.com>
 * @version 1.0.0
 *
 */
class ImageUploadBehavior extends Behavior
{

    const EVENT_AFTER_FILE_SAVE = 'afterFileSave';

    /**
     * Uplaod file attribute name
     *
     * @var string
     */
    public $attribute = '';
    public $filenameAttribute = null;

    /**
     * Thumbnail configs
     *
     * @var array
     */
    public $thumb = [
        'generate' => false
    ];

    /**
     * Watermark configs
     *
     * @var array
     */
    public $watermark = [
        'generate' => false
    ];

    /**
     * File save path
     *
     * @var string
     */
    private $filePath = '@webroot/uploads/[[ymd]]/[[random]].[[extension]]';

    /**
     * @var \yii\web\UploadedFile
     */
    protected $file;
    private $_rootPath;
    private $_oldPath;
    private $_filePath;

    public function init()
    {
        parent::init();
        $this->_rootPath = Yii::getAlias('@webroot');
    }

    /**
     * @inheritdoc
     */
    public function events()
    {
        return [
            ActiveRecord::EVENT_AFTER_FIND => 'afterFind',
            ActiveRecord::EVENT_BEFORE_VALIDATE => 'beforeValidate',
            ActiveRecord::EVENT_BEFORE_INSERT => 'beforeSave',
            ActiveRecord::EVENT_BEFORE_UPDATE => 'beforeSave',
            ActiveRecord::EVENT_AFTER_INSERT => 'afterSave',
            ActiveRecord::EVENT_AFTER_UPDATE => 'afterSave',
            ActiveRecord::EVENT_BEFORE_DELETE => 'beforeDelete',
            self::EVENT_AFTER_FILE_SAVE => 'afterFileSave',
        ];
    }

    private function getThumbnailPath($path)
    {
        $filename = \yii\helpers\StringHelper::basename($path);

        return str_replace($filename, '', $path) . str_replace('.', '_thumb.', $filename);
    }

    private function removeFile()
    {
        $file = $this->_rootPath . $this->_oldPath;
        if (is_file($file)) {
            @unlink($file);
        }

        // Remove thumb if exists
        $thumb = $this->getThumbnailPath($file);
        if (is_file($thumb)) {
            @unlink($thumb);
        }
    }

    public function afterFind()
    {
        $this->_oldPath = $this->owner->{$this->attribute};
    }

    /**
     * Before validate event.
     */
    public function beforeValidate()
    {
        $owner = $this->owner;
        $img = $owner->{$this->attribute};
        if (IsHelper::base64Image($img)) {
            $tempFilename = tempnam(sys_get_temp_dir(), 'bs-upload-');
            if (($index = stripos($img, ';base64')) !== false) {
                $type = substr($img, 5, $index - 5);
            } else {
                $type = mime_content_type($tempFilename);
            }
            $name = "tmp.";
            if (($index = stripos($type, '/')) !== false) {
                $name .= substr($type, $index + 1);
            } else {
                $name .= 'jpg';
            }
            file_put_contents($tempFilename, ImageHelper::base64Decode($img));
            $_FILES[$this->attribute] = [
                'name' => $name,
                'tmp_name' => $tempFilename,
                'type' => $type,
                'size' => filesize($tempFilename),
                'tmp_resource' => fopen($tempFilename, 'r'),
                'error' => UPLOAD_ERR_OK,
            ];
        }

        $this->file = UploadedFile::getInstance($owner, $this->attribute);
        !$this->file && $this->file = UploadedFile::getInstanceByName($this->attribute);

        if ($this->file instanceof UploadedFile) {
            $owner->{$this->attribute} = $this->file;
        } else if (!$owner->isNewRecord) {
            $owner->{$this->attribute} = $this->_oldPath;
        }
    }

    /**
     * Before save event.
     *
     * @throws \yii\base\InvalidConfigException
     */
    public function beforeSave()
    {
        if ($this->file instanceof UploadedFile) {
            $this->_filePath = $this->resolvePath($this->filePath);
            $owner = $this->owner;
            $owner->{$this->attribute} = str_replace($this->_rootPath, '', $this->_filePath);
            if ($this->filenameAttribute && empty($owner->{$this->filenameAttribute})) {
                $owner->{$this->filenameAttribute} = $this->file->getBaseName();
            }
        }
    }

    /**
     * Replaces all placeholders in path variable with corresponding values
     *
     * @param string $path
     * @return string
     */
    public function resolvePath($path)
    {
        $pairs = [
            '[[ymd]]' => date('Ymd'),
            '[[random]]' => StringHelper::generateRandomString(),
            '[[extension]]' => strtolower(pathinfo($this->owner->{$this->attribute})['extension']),
        ];

        return strtr(Yii::getAlias($path), $pairs);
    }

    /**
     * After save event.
     */
    public function afterSave()
    {
        if ($this->file instanceof UploadedFile) {
            $path = $this->_filePath;
            @mkdir(pathinfo($path, PATHINFO_DIRNAME), 0777, true);
            if (!$this->file->saveAs($path)) {
                throw new \Exception('File saving error.');
            }
            $owner = $this->owner;
            if (!$owner->isNewRecord && !empty($owner->oldAttributes[$this->attribute])) {
                $this->removeFile();
            }
            $this->owner->trigger(static::EVENT_AFTER_FILE_SAVE);
        }
    }

    /**
     * Before delete event.
     */
    public function beforeDelete()
    {
        $this->removeFile();
    }

    /**
     * After file save
     * Generate image thumb, if need.
     */
    public function afterFileSave()
    {
        $images = [
            'original' => $this->_filePath
        ];
        // Thumbnail
        $thumb = $this->thumb;
        if (isset($thumb['generate']) && $thumb['generate']) {
            // Generate thumb image
            $images['thumbnail'] = $this->getThumbnailPath($images['original']);
            Image::thumbnail($images['original'], $thumb['width'], $thumb['height'])->save($images['thumbnail']);
        }

        // Add watermark to image
        $watermark = $this->watermark;
        if (isset($watermark['generate']) && $watermark['generate'] && !empty($watermark['content'])) {
            foreach ($images as $image) {
                if ($watermark['type'] == 'text') {
                    Image::text($image, $watermark['content'], __DIR__ . '/simkai.ttf', [10, 10])->save($image);
                } elseif ($watermark == 'image' && is_file($watermark['content'])) {
                    Image::watermark($image, $watermark['content']);
                }
            }
        }
    }

}
