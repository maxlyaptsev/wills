<?php
namespace Triangle\Services\Instagram;

use Bitrix\Main\Loader;

Class Import {

    const TOKEN = '1425465497.f47bb3e.b8321e616b774a2b81af2a930c503c99';
    const IBLOCK_ID = 21;

    protected static $instance = null;

    protected function __construct()
    {
        Loader::includeModule('iblock');
    }

    private function getExisting()
    {
        $list = [];
        $imagesRes = \CIBlockElement::GetList(false, [
            'IBLOCK_ID' => \Triangle\Config::IBLOCK_INSTAGRAM
        ], false, false, ['ID','PROPERTY_ID']);
        while ($imageData = $imagesRes->GetNext())
        {
            $list[$imageData['PROPERTY_ID_VALUE']] =  $imageData['ID'];
        }

        return $list;
    }

    /**
     * @return self
     */
    public static function getInstance()
    {
        if (!isset(static::$instance))
            static::$instance = new static();

        return static::$instance;
    }

    /**
     * Получаем записи из инстаграма и сохраняем как элементы инфоблока. Сущестующие записи не обновляются, только добавление новых
     *
     * Т.к. приложение в sandbox режиме можно получить только 20 записей
     * поэтому min_id пока бесполезен. Так же есть параметр count
     * Подробнее по ссылке
     * @link https://www.instagram.com/developer/endpoints/users/#get_users
     *
     * @param string $id
     * @return bool
     */
    public function start($id = '') {

        $list = $this->getExisting();

        /**
         * Запрашиваем новые. Если записи нет, то добавится
         * Если есть - пропускаем. Обновления не предусмотрены
         */
        $client = new \GuzzleHttp\Client();
        $res = $client->request('GET', 'https://api.instagram.com/v1/users/self/media/recent/?access_token=' . self::TOKEN . '&min_id=' . $id);
        $response = json_decode($res->getBody());

        if($res->getStatusCode() == 200) {
            $element = new \CIBlockElement();

            foreach ($response->data as $item) {

                // существует. Можно обновить
                if($list[$item->id])
                    continue;

                $item = [
                    'IBLOCK_ID' => self::IBLOCK_ID,
                    'NAME' => substr(strip_tags($item->caption->text), 0, 100),
                    'PREVIEW_PICTURE'    => \CFile::MakeFileArray($item->images->standard_resolution->url),
                    'PREVIEW_TEXT'      => $item->caption->text,
                    "PREVIEW_TEXT_TYPE" => 'html',
                    "ACTIVE_FROM" => date('d.m.Y H:i:s', $item->created_time),
                    'PROPERTY_VALUES' => [
                        'ID'       => $item->id,
                        'TYPE'      => $item->type,
                        'LINK'      => $item->link,
                        'LIKES'    => $item->likes->count,
                        'COMMENTS' => $item->comments->count,
                        'TAGS'     => $item->tags
                    ]
                ];

                $res = $element->Add($item);

                if(!$res)
                {
                    echo $element->LAST_ERROR . '\r\n';
                }
            }

        }

        return true;
    }

    public function agent()
    {
        $this->start();
        return 'Triangle\Services\Instagram\Import::getInstance()->agent();';
    }

}