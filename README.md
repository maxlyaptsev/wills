Привет

Дать доступ к целому проекту не могу - безопасность, все дела. Приведу некоторые куски кода с объяснением.

## Работа с внешними api
### Задача: получить посты по тегам из вконтакте, выдать в формате, удобном для мобильного приложения.

Решение: найти библиотеку где реализован интерфейс работы с вк, сделать свою надстройку для получения нужных данных.
Взял [этот sdk](https://github.com/bocharsky-bw/vkontakte-php-sdk)

Решение [vkontakte.php](vkontakte.php)

### Instagram: задача с некоторой периодичностью подтягивать фотографии из аккаунта.
Получаем токен, для http запросов используем guzzle. В последнем обновлении апи инстаграма позволяет получать только последние 20 постов, поэтому частота запуска скрипта должна соответствовать плотности публикаций.
Ставим на крон, [instagram.php](instagram.php)
Здесь реализован агент который занимается роутингом крон задач. Крон каждую минуту его дергает, в агенте указывается точное время или частота вызова метода\функции, в ответ необходимо отдать вызов функции, такой замедленно-рекурсивный способ перезапуска.
```php
    public function agent()
    {
        $this->start();
        return 'Triangle\Services\Instagram\Import::getInstance()->agent();';
    }
```
Кстати на нем же делал более интересную вещь - очередь. Из crm периодически тянутся некие продукты
### Реализация простой очереди
```php
public static function updateProductsFromCrmAgent($timeFrom)
	{
		$time = \DateTime::createFromFormat('d.m.Y H:i:s', $timeFrom);
		$curTime = (new \DateTime());

		$import = new static();
		$import->updateProductsFromCrm($time);
		return '\Synergy\Exchange\Products::updateProductsFromCrmAgent("'. $curTime->format('d.m.Y H:i:s').'");';
	}
```
В аргументе указана определенное время - тянем продукты, которые изменились или добавились начиная с этого времени и позже, а возвращаем текущее время после выполнения операции. В следующий раз будут тянутся продукты начиная с обновленного метода. Если что-то пойдет не так, время останется преждим, и будут новые попытки получить нужную информацию. Параллельно, оповещая кого нужно.

## логирование
Задача: логи в зависимости от уровня должны уходить в разные источники, дебаги только в файлы, инфо и выше в базу данных, warning и error в чат, на которые необходимо быстро реагировать. Основа - монолог. Сделал обертку и 2 хендлера - под чат mattermost и под базу данных (конкретно в этом случае это highload block). 
```php
<?php
namespace Synergy;

/**
 * Логирование
 * Class Logger
 *
 * @package Synergy
 */
class Logger {

	private $instance = null;

	private function __construct($channel)
	{

	}

    /**
     * @param $channel
     *
     * @return \Monolog\Loggerer
     */
	public static function getInstance($channel) {

		$logger = new \Monolog\Logger($channel);

		$fileStream = new \Monolog\Handler\RotatingFileHandler("/home/bitrix/www/local/monolog/{$channel}/log.html", 500, \Monolog\Logger::DEBUG);
		$formatter = new \Monolog\Formatter\VisualFormatter();
		$fileStream->setFormatter($formatter);
		$logger->pushHandler($fileStream);

		$logger->pushHandler(new \Monolog\Handler\HighLoadBlockHandler(14, \Monolog\Logger::DEBUG, 20000)); // все выводим в hl блоки, вообще все
		$logger->pushHandler(new \Monolog\Handler\MattermostHandler('https://mm.synergy.ru/hooks/refa5d31jidafx3k7btwspt8ba', \Monolog\Logger::WARNING)); // в чат только ошибки

		$logger->pushProcessor(function ($record) {

			$trace = new \Monolog\Processor\IntrospectionProcessor(\Monolog\Logger::DEBUG); 
			$record = $trace($record);


			$git = new \Monolog\Processor\GitProcessor(\Monolog\Logger::WARNING); // добавляем информацию о гите если выше warning
			$record = $git($record);

			return $record;
		});

		return $logger;
	}




}
```
Обертка сделана для удобства, монолог имеет довольно сложную конструкцию. В итоге имеем простую запись:
```php
\Synergy\Logger::getInstance('core')->warning('что-то случилось');
```
Пример хендлера для mattermost
```php
<?php
namespace Monolog\Handler;
use GuzzleHttp\Client;
use Monolog\Logger;
use Monolog\Handler\AbstractProcessingHandler;

/**
 * Отправка сообщений в катал mattermost
 * Class MattermostHandler
 *
 * @package Monolog\Handler
 */
class MattermostHandler extends AbstractProcessingHandler
{
    /**
     * @var 
     */
	private $webHookUrl;

    /**
     * @var Client 
     */
	private $client;

    /**
     * MattermostHandler constructor.
     * @param $webHookUrl
     * @param int $level
     * @param bool $bubble
     * @param null $client
     */
	public function __construct($webHookUrl, $level = Logger::DEBUG, $bubble = true, $client = null)
	{
		parent::__construct($level, $bubble);
		$this->webHookUrl = $webHookUrl;
		$this->client = ($client) ?: new Client();
	}

    /**
     * Реализация записи логов
     * @param array $record
     */
	public function write(array $record)
	{
		$this->client->post($this->webHookUrl, [
			'body' => [
				'payload' => json_encode([
					'attachments' => [
						[
							"fallback"=> $record['formatted'],
							"color"=> $this->getColor($record['level']),
							'text' => "{$record['level_name']}: {$record['message']}\r\n_{$record['extra']['file']}:{$record['extra']['line']}_"
						]
					],
					'username' => 'Monolog',
					"icon_url" => "https://my.synergy.ru/upload/icons/fire.jpg"
				]),
			]
		]);
	}

	/**
	 * Цвет для сообщений
	 * @param $level
	 *
	 * @return string
	 */
	public function getColor($level)
	{

		switch ($level) {
			case Logger::ERROR:
				return '#f00';
				break;
			case Logger::WARNING:
				return '#FF8000';
				break;
			default:
				return '#FF8000';
				break;

		}
	}
}
```


## работа с yii2 
SaaS решение. Задача: Пользователь заполняет некие простые формы с моментальным сохранением данных (как в google docs)
Есть функционал: создать документ, отредактировать, подписать, отправить на подпись второй стороне, предоставить доступ по ссылке.
Одно из ключевых требований - документ можно скачивать в pdf формате.
[Вот контроллер, как есть, неотрефакторенный](DocumentUserController.php)
Пояснения:
actionDownload - отвечает как раз за загрузку pdf файла. Генерирует pdf, наверное самая мощный инструмент для этого [https://wkhtmltopdf.org/](https://wkhtmltopdf.org/). mPDF, который устанавливается элементарно через композер и не требует настройки не подошел. Генерация происходит медленней, отличие html версии от pdf критическое. 
Для упрощения работы как обычно взял готовое решение - [snappy](https://github.com/KnpLabs/snappy)
Документы разные, шаблоны хранятся отдельно 
```php
$content = $this->renderPartial("templates/{$model->document->code}/print",[
            'model' => $model,
            'fields' => $this->renderDocumentFields($model),
        ]);
```
Тут интересен класс работы с полями. Поля могут быть как одиночные, так и множественные, типа enum
База данных спроектирована таким образом (http://joxi.net/D2PgzQBtpq0p0m)
Т.е. есть описание документа, для вывода в публичной части, у каждого документа есть список полей. Это общие данные. 
Когда пользователь создает документ, добавляется новая запись в document_user, при заполнении полей - document_user_fields, а информацию о поле можно получить обратившись к связанному полю. 
Это дает возможность выводить формы в простом формате: 
```
    <?= $form->field($field, '['.$field->field->code.']value', ['template' => '{label}<div class="col-sm-5">{input}</div>'])
        ->label($field->field->name, ['class'=>'col-sm-3  col-md-3  col-lg-3 control-label'])
        ->textInput(['placeholder'=>$field->field->placeholder]) ?>
```
В планах было создание редактора, поэтому каждое такое поле вынесено в виджеты.
Просмотр по ссылке - в интерфейсе есть кнопка - предоставить доступ по ссылке, генерируется строка, которая записывается в таблицу document_user, при переходе по ссылке по строке находится нужный документ, метод actionProtectedView. Оптимизация скорости не проводилась, поэтому просто напишу тут свои мысли - искать по хешу будет долго, держать индекс из-за такой не очень частой операции смысле нет, поэтому хорошо в хеш добавлять айди документа, по которому находить нужную строку из базы данных и проверять на ней хеш.

Чтобы не было возможности удалять или редактировать чужие документы реализована проверка через rbac
```php
if (!\Yii::$app->authManager->checkAccess(User::getUserId(), 'updateDocument',['document' => $model])) {
            throw new ForbiddenHttpException('У вас нет прав удалять чужие документы');
        }
```
Этот доступ можно было бы проверять проще - через behaviors, но поступила задача - предоставлять доступ к полному функционалу даже не авторизованных пользователей. Но при регистрации все данные должны быть сохранены и записаны в профиль. Поэтому User::getUserId() внутри при необходимости создает гостевого пользователя и добавляет его в базу данных, при этом в сессии остается информация чтобы связать данные. В итоге неавторизованный пользователь работает точно так же как авторизованный. На роли пользователей установлено правило проверки - является ли он владельцем документа:
```php
public function execute($user, $item, $params)
    {

        $documentUser = $params['document'];
        /**
         * @var $documentUser DocumentUser
         */
        return isset($documentUser) ? $documentUser->getAttribute('user_id') === User::getUserId() : false;
    }
```
Таким образом в контроллере есть сформированные поля для документа, проверка доступа, геренация pdf. В шаблонах только отображаем данные. Такая реализация позволяет легко прикрутить rest интерфейс.

## Организация работы
Работаю локально, через vagrant, для деплоя использую pipelines, соответственно, отрабатывают миграции, композер ставит зависимости, подтягивается весь код. Каждая задача в идеале в своей ветке.
Документирую api для приложений в основном в swagger, у себя для теста postman - он намного удобнее, в нем есть даже тесты. В идеале только им бы и пользовался. 
Доки пишу в репозитории. phpDocumentor мало кто смотрит, хотя описание генерируется и туда. Логирование через моноголог, настроены различные каналы. Самые важные события уходят в чат. Как и хуки на изменения в репозитории - пуши, мердж реквесты. 
