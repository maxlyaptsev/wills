<?
namespace Synergyfriends\Import;
use Bitrix\Socialservices\UserTable;
use \BW\Vkontakte as Vk;

Class Vkontakte {

    /**
     * Настройки приложения. Не должны меняться.
     * @var int
     */
    const REDIRECT_URI = 'https://oauth.vk.com/blank.html';

    private $groupId =  123456;
    private $clientSecret = 'gtjWNjdubJBkNTKueyyx';
    private $accessToken = '1a813ee51a813ee51a813ee5981ada020e11a811a813ee5434a64cffcfd8ab850c102ax';

    private $error;

    /**
     * Что ищем
     * @var string
     */
    public $query = '';

    /**
     * Сколько
     * @var int
     */
    public $count = 10;

    /**
     * Отступ
     * @var int
     */
    public $offset = 0;

    private $vk;

    public function __construct()
    {
        $this->vk = new Vk([
            'client_id' => '6204631',
            'client_secret' => '6QgWvoL2Ry0J3ezUelrK',
            'redirect_uri' => self::REDIRECT_URI,
            'response_type' => 'token',
            'scope' => [
                'offline',
                'wall'
            ]
        ]);

        $this->vk->setAccessToken(json_encode(['access_token' => $this->accessToken]));
    }


    public function setQuery($query)
    {
        $this->query = $query;
    }

    private function setError($error)
    {
        $this->error = $error;
    }


    /**
     * Получим записи
     * @param $params
     * Доступные ключи:
     *      count 10
     *      offset 0
     *      type news | business | networking
     * @return mixed
     * @throws \Exception
     */
    public function get($params = [])
    {
        foreach ($params as $param => $value)
        {
            switch ($param)
            {
                case 'count':
                    $this->count = (int)$value;
                    break;
                case 'offset':
                    $this->offset = (int)$value;
                    break;
                case 'type':
                    switch ($value)
                    {
                        case 'news':
                            $this->setQuery('#news');
                            break;
                        case 'business':
                            $this->setQuery('#business');
                            break;
                        case 'networking':
                            $this->setQuery('#networking');
                            break;
                    }

                    break;
            }
        }

        if($this->query === '')
        {
            return [
                'result' => 0,
                'error' => 'Не задана категория type = news|business|networking'
            ];
        }

        $data = $this->vk->api('wall.search', [
            'owner_id' => -$this->groupId,
            'query' => $this->query,
            'count' => $this->count,
            'offset' => $this->offset,
            'owners_only' => 1
        ]);

        $items = [];
        foreach ($data['items'] as $item)
        {
            foreach ($item['attachments'] as $attachment)
            {
                switch ($attachment['type'])
                {
                    case 'photo':
                        $item['photo'] = $attachment['photo']['photo_604'];
                        break;
                    case 'link':
                        $item['link'] = $attachment['link']['url'];
                        break;
                }
            }
            $items[] = [
                'id' => $item['id'],
                'date' => date('d.m.Y H:i:s', $item['date']),
                'name' => 'Школа Бизнеса СИНЕРГИЯ. MBA. Семинары',
                'text' => $item['text'],
                'photo' => $item['photo'],
                'link' => $item['link'],
                'comments' => $item['comments']['count'],
            ];
        }

        return [
            'result' => 1,
            'data' => $items
        ];

    }


    /**
     * Получить пост по id
     * @param $postId int
     * @return array
     * @throws \Exception
     */
    public function getById($postId)
    {
        $data = $this->vk->api('wall.getById', [
            'posts' => -$this->groupId . "_" . $postId,
        ]);

        $data = $data[0];

        foreach ($data['attachments'] as $attachment)
        {
            switch ($attachment['type'])
            {
                case 'photo':
                    $data['photo'] = $attachment['photo']['photo_604'];
                    break;
                case 'link':
                    $data['link'] = $attachment['link']['url'];
                    break;
            }
        }

        $data = [
            'id' => $data['id'],
            'date' => date('d.m.Y H:i:s', $data['date']),
            'name' => 'Школа Бизнеса СИНЕРГИЯ. MBA. Семинары',
            'text' => $data['text'],
            'photo' => $data['photo'],
            'link' => $data['link'],
        ];


        $comments = $this->getComments($postId);
        if($comments['result'] == 1)
        {
            $data['comments'] = $comments['data'];
        }

        return [
            'result' => 1,
            'data' => $data
        ];
    }


    /**
     * Добавить пост.
     * Стена должна быть открыта либо пишем от имеи сообщества
     * @param $message
     * @param $userId
     * @return array
     * @throws \Exception
     */
    public function addPost($message, $userId)
    {
        $vkAuth = $this->getVkAuth($userId);

        if($vkAuth['result'] == 0)
        {
            return $vkAuth;
        }
        else
        {
            $userToken = $vkAuth['data']['OATOKEN'];
        }

        $this->vk->setAccessToken(json_encode(['access_token' => $userToken]));

        $data = $this->vk->api('wall.post', [
            'owner_id' => -$this->groupId,
            'message' => $message,
            // 'from_group' => 1, // пишем от сообщества (юзер и токен должен быть админом)
            'v' => '5.68'
        ]);


        if($data['error'])
        {
            return [
                'result' => 0,
                'error' => $data['error']
            ];
        }

        return [
            'result' => 1,
            'data' => $data
        ];
    }


    /**
     * Коменты могут писать только те что авторизованы через vk либо есть привязка к профилю
     * @param $userId
     * @return array
     * @throws \Bitrix\Main\ArgumentException
     */
    private function getVkAuth($userId)
    {

        $sf = new \cMainSF;
        $userId = $sf->GetUserByGUID($userId);


        if(!$userId)
        {
            return [
                'result' => 0,
                'error' => 'Пользователь не найден'
            ];
        }

        $social = \Bitrix\Socialservices\UserTable::getList([
            'filter' => [
                'USER_ID' => $userId,
                'EXTERNAL_AUTH_ID' => 'VKontakte'
            ],
            'select' => ['EXTERNAL_AUTH_ID', 'OATOKEN', 'ID']
        ])->fetch();

        if(!$social)
        {
            return [
                'result' => 0,
                'error' => 'Пользователь авторизован не через Вконтакте'
            ];
        }

        return [
            'result' => 1,
            'data' => $social
        ];
    }




    /**
     * Добавить комментарий в пост
     * @param $postId
     * @param $message
     * @param $userId
     * @return array|mixed
     * @throws \Exception
     */
    public function addComment($postId, $message, $userId)
    {

        $vkAuth = $this->getVkAuth($userId);

        if($vkAuth['result'] == 0)
        {
            return $vkAuth;
        }
        else
        {
            $userToken = $vkAuth['data']['OATOKEN'];
        }

        $this->accessToken = $userToken;
        $this->vk->setAccessToken(json_encode(['access_token' => $this->accessToken]));

        $data = $this->vk->api('wall.createComment', [
            'owner_id' => -$this->groupId,
            'post_id' => $postId,
            'from_group' => 0,
            'message' => $message,
        ]);

        return $data;
    }


    /**
     * Получить комментарии поста
     * @param $id
     * @param int $count
     * @param int $offset
     * @return array
     * @throws \Exception
     */
    public function getComments($id, $count = 10, $offset = 0)
    {
        $data = $this->vk->api('wall.getComments', [
            'owner_id' => -$this->groupId,
            'post_id' => $id,
            'count' => $count,
            'offset' => $offset,
            'extended' => 1
        ]);

        if($data['count'] == 0)
        {
            return [
                'result' => 0,
                'error' => "у этой записи нет комментариев [$id]"
            ];
        }

        foreach ($data['profiles'] as $profile)
        {
            $users[$profile['id']] = $profile;
        }

        foreach ($data['items'] as $key => $item)
        {
            $item['date'] = date('d.m.Y H:i:s', $item['date']);
            $item['name'] = $users[$item['from_id']]['first_name'] . ' ' . $users[$item['from_id']]['last_name'];
            $item['photo'] = $users[$item['from_id']]['photo_100'];

            unset($item['from_id']);
            unset($item['attachments']);
            $data['items'][$key] = $item;
        }

        return [
            'result' => 1,
            'data' => $data['items']
        ];
    }

}

?>
