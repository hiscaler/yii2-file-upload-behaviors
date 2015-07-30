<?php

namespace yadjet\behaviors;

use Yii;
use yii\base\Behavior;
use yii\db\ActiveRecord;
use yii\web\UploadedFile;
use yadjet\helpers\StringHelper;

/**
 * File uploaded behavior class.
 *
 * @author hiscaler <hiscaler@gmail.com>
 * @version 1.0.0
 *
 */
class FileUploadBehavior extends Behavior
{

    const EVENT_AFTER_FILE_SAVE = 'afterFileSave';

    /**
     * Uplaod file attribute name
     * @var string
     */
    public $attribute = '';

    /**
     * File save path
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
        ];
    }

    private function removeFile()
    {
        $file = $this->_rootPath . $this->_oldPath;
        if (is_file($file)) {
            @unlink($file);
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
        $this->file = UploadedFile::getInstance($owner, $this->attribute);
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
            $this->owner->{$this->attribute} = str_replace($this->_rootPath, '', $this->_filePath);
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
                throw new Exception('File saving error.');
            }
            $owner = $this->owner;
            if (!$owner->isNewRecord && !empty($this->_oldPath)) {
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
        
    }

}
