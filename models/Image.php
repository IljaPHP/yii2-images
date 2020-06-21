<?php


/**
 * This is the model class for table "image".
 *
 * @property integer $id
 * @property string  $filePath
 * @property integer $itemId
 * @property integer $isMain
 * @property string  $modelName
 * @property string  $urlAlias
 * @property integer $sort
 * @property boolean $isMain
 */

namespace rico\yii2images\models;

use rico\yii2images\ModuleTrait;
use Yii;
use yii\base\Exception;
use yii\helpers\BaseFileHelper;
use yii\helpers\Url;


class Image extends \yii\db\ActiveRecord
{
    use ModuleTrait;


    private $helper = false;

    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return '{{%image}}';
    }

    /**
     * @param int $id
     * @param int $newSort
     */
    public static function updateSortOrder(int $id, int $newSort)
    {
        $image = self::findOne($id);
        $image->setSort($newSort);
        if($newSort === 0){
            $image->setMain();
        }
        $image->save();
        $images = self::find()
            ->andWhere(['modelName' => $image->modelName])
            ->andWhere(['itemId' => $image->itemId])
            ->andWhere(['!=', 'id', $image->id])
            ->andWhere(['>=', 'sort', $image->getSort()]);
        foreach ($images->each() as $each) {
            $each->updateCounters(['sort' => 1]);
        }
    }

    public function setSort(int $sort)
    {
        $this->sort = $sort;
    }

    /**
     * @return mixed|null
     */
    public function getSort()
    {
        return $this->sort;
    }

    public function clearCache()
    {
        $subDir = $this->getSubDur();

        $dirToRemove = $this->getModule()->getCachePath() . DIRECTORY_SEPARATOR . $subDir;

        if (preg_match('/' . preg_quote($this->modelName, '/') . '/', $dirToRemove)) {
            BaseFileHelper::removeDirectory($dirToRemove);
        }

        return true;
    }

    /**
     * @return string
     */
    protected function getSubDur(): string
    {
        return \yii\helpers\Inflector::pluralize($this->modelName) . '/' . $this->modelName . $this->itemId;
    }

    public function getUrl($size = false)
    {
        $urlSize = ($size) ? '_' . $size : '';
        $url = Url::toRoute(
            [
                '/' . $this->getModule()->id . '/images/image-by-item-and-alias',
                'item' => $this->modelName . $this->itemId,
                'dirtyAlias' => $this->urlAlias . $urlSize . '.' . $this->getExtension(),
            ]
        );

        return $url;
    }

    public function getExtension()
    {
        $ext = pathinfo($this->getPathToOrigin(), PATHINFO_EXTENSION);

        return $ext;
    }

    public function getPathToOrigin()
    {
        $base = $this->getModule()->getStorePath();

        $filePath = $base . DIRECTORY_SEPARATOR . $this->filePath;

        return $filePath;
    }

    public function getContent($size = false)
    {
        return file_get_contents($this->getPath($size));
    }

    public function getPath($size = false)
    {
        $urlSize = ($size) ? '_' . $size : '';
        $base = $this->getModule()->getCachePath();
        $sub = $this->getSubDur();

        $origin = $this->getPathToOrigin();

        $filePath = $base . DIRECTORY_SEPARATOR .
            $sub . DIRECTORY_SEPARATOR . $this->urlAlias . $urlSize . '.' . pathinfo($origin, PATHINFO_EXTENSION);;
        if (!file_exists($filePath)) {
            $this->createVersion($origin, $size);

            if (!file_exists($filePath)) {
                throw new \Exception('Problem with image creating.');
            }
        }

        return $filePath;
    }

    public function createVersion($imagePath, $sizeString = false)
    {
        if (strlen($this->urlAlias) < 1) {
            throw new \Exception('Image without urlAlias!');
        }

        $cachePath = $this->getModule()->getCachePath();
        $subDirPath = $this->getSubDur();
        $fileExtension = pathinfo($this->filePath, PATHINFO_EXTENSION);

        if ($sizeString) {
            $sizePart = '_' . $sizeString;
        } else {
            $sizePart = '';
        }

        $pathToSave = $cachePath . '/' . $subDirPath . '/' . $this->urlAlias . $sizePart . '.' . $fileExtension;

        BaseFileHelper::createDirectory(dirname($pathToSave), 0777, true);


        if ($sizeString) {
            $size = $this->getModule()->parseSize($sizeString);
        } else {
            $size = false;
        }

        if ($this->getModule()->graphicsLibrary == 'Imagick') {
            $image = new \Imagick($imagePath);

            $image->setImageCompressionQuality($this->getModule()->imageCompressionQuality);

            if ($size) {
                if ($size['height'] && $size['width']) {
                    $image->cropThumbnailImage($size['width'], $size['height']);
                } elseif ($size['height']) {
                    $image->thumbnailImage(0, $size['height']);
                } elseif ($size['width']) {
                    $image->thumbnailImage($size['width'], 0);
                } else {
                    throw new \Exception('Something wrong with this->module->parseSize($sizeString)');
                }
            }

            $image->writeImage($pathToSave);
        } else {
            $image = new \abeautifulsite\SimpleImage($imagePath);

            if ($size) {
                if ($size['height'] && $size['width']) {
                    $image->thumbnail($size['width'], $size['height']);
                } elseif ($size['height']) {
                    $image->fit_to_height($size['height']);
                } elseif ($size['width']) {
                    $image->fit_to_width($size['width']);
                } else {
                    throw new \Exception('Something wrong with this->module->parseSize($sizeString)');
                }
            }

            //WaterMark
            if ($this->getModule()->waterMark) {
                if (!file_exists(Yii::getAlias($this->getModule()->waterMark))) {
                    throw new Exception('WaterMark not detected!');
                }

                $wmMaxWidth = intval($image->get_width() * 0.4);
                $wmMaxHeight = intval($image->get_height() * 0.4);

                $waterMarkPath = Yii::getAlias($this->getModule()->waterMark);

                $waterMark = new \abeautifulsite\SimpleImage($waterMarkPath);


                if (
                    $waterMark->get_height() > $wmMaxHeight
                    or
                    $waterMark->get_width() > $wmMaxWidth
                ) {
                    $waterMarkPath = $this->getModule()->getCachePath() . DIRECTORY_SEPARATOR .
                        pathinfo($this->getModule()->waterMark)['filename'] .
                        $wmMaxWidth . 'x' . $wmMaxHeight . '.' .
                        pathinfo($this->getModule()->waterMark)['extension'];

                    //throw new Exception($waterMarkPath);
                    if (!file_exists($waterMarkPath)) {
                        $waterMark->fit_to_width($wmMaxWidth);
                        $waterMark->save($waterMarkPath, 100);
                        if (!file_exists($waterMarkPath)) {
                            throw new Exception('Cant save watermark to ' . $waterMarkPath . '!!!');
                        }
                    }
                }

                $image->overlay($waterMarkPath, 'bottom right', .5, -10, -10);
            }

            $image->save($pathToSave, $this->getModule()->imageCompressionQuality);
        }

        return $image;
    }

    public function getSizesWhen($sizeString)
    {
        $size = $this->getModule()->parseSize($sizeString);
        if (!$size) {
            throw new \Exception('Bad size..');
        }


        $sizes = $this->getSizes();

        $imageWidth = $sizes['width'];
        $imageHeight = $sizes['height'];
        $newSizes = [];
        if (!$size['width']) {
            $newWidth = $imageWidth * ($size['height'] / $imageHeight);
            $newSizes['width'] = intval($newWidth);
            $newSizes['height'] = $size['height'];
        } elseif (!$size['height']) {
            $newHeight = intval($imageHeight * ($size['width'] / $imageWidth));
            $newSizes['width'] = $size['width'];
            $newSizes['height'] = $newHeight;
        }

        return $newSizes;
    }

    public function getSizes()
    {
        $sizes = false;
        if ($this->getModule()->graphicsLibrary == 'Imagick') {
            $image = new \Imagick($this->getPathToOrigin());
            $sizes = $image->getImageGeometry();
        } else {
            $image = new \abeautifulsite\SimpleImage($this->getPathToOrigin());
            $sizes['width'] = $image->get_width();
            $sizes['height'] = $image->get_height();
        }

        return $sizes;
    }

    /**
     * @param bool $isMain
     */
    public function setMain($isMain = true): void
    {
        if ($isMain) {
            $this->isMain = 1;
            $images = self::find()
                ->andWhere(['modelName' => $this->modelName])
                ->andWhere(['itemId' => $this->itemId])
                ->andWhere(['!=', 'id', $this->id]);
            if ($images) {
                foreach ($images->each() as $each) {
                    $each->setMain(false);
                    $each->save();
                }
            }
        } else {
            $this->isMain = 0;
        }
    }

    public function getMimeType($size = false)
    {
        return image_type_to_mime_type(exif_imagetype($this->getPath($size)));
    }

    /**
     * @inheritdoc
     */
    public function rules(): array
    {
        return [
            [['filePath', 'itemId', 'modelName', 'urlAlias'], 'required'],
            [['itemId', 'isMain', 'sort'], 'integer'],
            [['name'], 'string', 'max' => 80],
            [['filePath', 'urlAlias'], 'string', 'max' => 400],
            [['modelName'], 'string', 'max' => 150],
        ];
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels(): array
    {
        return [
            'id' => 'ID',
            'filePath' => 'File Path',
            'itemId' => 'Item ID',
            'isMain' => 'Is Main',
            'modelName' => 'Model Name',
            'urlAlias' => 'Url Alias',
            'sort' => 'Sort',
            'name' => 'Name',
        ];
    }

    protected function getFileName()
    {
        return basename($this->filePath);
    }
}
