<?php
/**
 * Módulo de Integração de Stock com Intranet
 * Compatível especificamente com PrestaShop 1.7.6.2
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class StockWebhook extends Module
{
    public function __construct()
    {
        $this->name = 'stockwebhook';
        $this->tab = 'administration';
        $this->version = '1.2.2';
        $this->author = 'Winespiritus';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Stock Webhook Intranet');
        $this->description = $this->l('Gestão e registo de envios de stock para a Intranet via Webhook (PS 1.7.6.2).');
        
        $this->ps_versions_compliancy = array('min' => '1.7.6.0', 'max' => '1.7.6.9');
    }

    public function install()
    {
        return parent::install() &&
            $this->createTables() &&
            Configuration::updateValue('STOCK_WEBHOOK_ACTIVE', 1) &&
            Configuration::updateValue('STOCK_WEBHOOK_API_URL', 'https://intranet.tuaempresa.com/api/stock-webhook') &&
            Configuration::updateValue('STOCK_WEBHOOK_SECRET', Tools::passwdGen(32)) &&
            $this->registerHook('actionOrderStatusUpdate') &&
            $this->registerHook('actionUpdateQuantity');
    }

    public function uninstall()
    {
        return parent::uninstall() &&
            $this->deleteTables() &&
            Configuration::deleteByName('STOCK_WEBHOOK_ACTIVE') &&
            Configuration::deleteByName('STOCK_WEBHOOK_API_URL') &&
            Configuration::deleteByName('STOCK_WEBHOOK_SECRET');
    }

    private function createTables()
    {
        $sql = "CREATE TABLE IF NOT EXISTS `" . _DB_PREFIX_ . "stock_webhook_log` (
            `id_log` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `event_type` VARCHAR(50) NOT NULL,
            `reference` VARCHAR(64) DEFAULT NULL,
            `quantity` INT(11) DEFAULT NULL,
            `status_id` INT(11) DEFAULT NULL,
            `http_code` INT(5) DEFAULT NULL,
            `response` VARCHAR(255) DEFAULT NULL,
            `date_add` DATETIME NOT NULL,
            PRIMARY KEY (`id_log`)
        ) ENGINE=" . _MYSQL_ENGINE_ . " DEFAULT CHARSET=utf8;";

        return Db::getInstance()->execute($sql);
    }

    private function deleteTables()
    {
        return Db::getInstance()->execute("DROP TABLE IF EXISTS `" . _DB_PREFIX_ . "stock_webhook_log`");
    }

    public function getContent()
    {
        $output = '';

        // Corrigido: captura de dados quando o formulário é submetido
        if (Tools::isSubmit('submitStockWebhookConfig')) {
            $active = (int)Tools::getValue('STOCK_WEBHOOK_ACTIVE');
            $api_url = trim(Tools::getValue('STOCK_WEBHOOK_API_URL'));
            $secret = trim(Tools::getValue('STOCK_WEBHOOK_SECRET'));

            if (empty($api_url) || !Validate::isUrl($api_url)) {
                $output .= $this->displayError($this->l('Por favor introduza um URL válido.'));
            } else {
                Configuration::updateValue('STOCK_WEBHOOK_ACTIVE', $active);
                Configuration::updateValue('STOCK_WEBHOOK_API_URL', $api_url);
                Configuration::updateValue('STOCK_WEBHOOK_SECRET', $secret);

                $output .= $this->displayConfirmation($this->l('Configurações guardadas com sucesso!'));
            }
        }

        return $output . $this->renderForm() . $this->renderLogList();
    }

    public function renderForm()
    {
        $fields_form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Configurações do Webhook'),
                    'icon' => 'icon-cogs'
                ),
                'input' => array(
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Ativar Webhook'),
                        'name' => 'STOCK_WEBHOOK_ACTIVE',
                        'is_bool' => true,
                        'values' => array(
                            array('id' => 'active_on', 'value' => 1, 'label' => $this->l('Sim')),
                            array('id' => 'active_off', 'value' => 0, 'label' => $this->l('Não'))
                        ),
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('URL da API (Intranet)'),
                        'name' => 'STOCK_WEBHOOK_API_URL',
                        'required' => true,
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Chave Secreta (X-PrestaShop-Secret-Key)'),
                        'name' => 'STOCK_WEBHOOK_SECRET',
                        'required' => true,
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Guardar'),
                    'name' => 'submitStockWebhookConfig', // CORRIGIDO: Nome do botão de submissão
                    'class' => 'btn btn-default pull-right'
                )
            )
        );

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->default_form_language = (int)Configuration::get('PS_LANG_DEFAULT');
        $helper->submit_action = 'submitStockWebhookConfig'; // CORRIGIDO: Ação explícita do formulário

        $helper->fields_value['STOCK_WEBHOOK_ACTIVE'] = Configuration::get('STOCK_WEBHOOK_ACTIVE');
        $helper->fields_value['STOCK_WEBHOOK_API_URL'] = Configuration::get('STOCK_WEBHOOK_API_URL');
        $helper->fields_value['STOCK_WEBHOOK_SECRET'] = Configuration::get('STOCK_WEBHOOK_SECRET');

        return $helper->generateForm(array($fields_form));
    }

    public function renderLogList()
    {
        $fields_list = array(
            'id_log' => array('title' => $this->l('ID'), 'align' => 'center', 'class' => 'fixed-width-xs'),
            'event_type' => array('title' => $this->l('Evento'), 'type' => 'text'),
            'reference' => array('title' => $this->l('Referência / SKU'), 'type' => 'text'),
            'quantity' => array('title' => $this->l('Qtd Enviada'), 'align' => 'center'),
            'http_code' => array('title' => $this->l('Status HTTP'), 'align' => 'center'),
            'date_add' => array('title' => $this->l('Data / Hora'), 'type' => 'datetime'),
        );

        $helper = new HelperList();
        $helper->shopLinkType = '';
        $helper->simple_header = false;
        $helper->identifier = 'id_log';
        $helper->actions = array();
        $helper->show_toolbar = false;
        $helper->title = $this->l('Histórico de Envio de Stocks (Intranet)');
        $helper->table = 'stock_webhook_log';
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;

        $sql = 'SELECT * FROM `' . _DB_PREFIX_ . 'stock_webhook_log` ORDER BY `id_log` DESC LIMIT 100';
        $logs = Db::getInstance()->executeS($sql);

        return $helper->generateList($logs ? $logs : array(), $fields_list);
    }

    public function hookActionOrderStatusUpdate($params)
    {
        if (!Configuration::get('STOCK_WEBHOOK_ACTIVE')) {
            return;
        }

        if (!isset($params['id_order']) || !isset($params['newOrderStatus'])) {
            return;
        }

        $order = new Order((int)$params['id_order']);
        $new_status = $params['newOrderStatus'];
        
        if (!Validate::isLoadedObject($order) || !Validate::isLoadedObject($new_status)) {
            return;
        }

        $products = $order->getProducts();
        $id_lang = (int)($this->context->language ? $this->context->language->id : Configuration::get('PS_LANG_DEFAULT'));

        $items = array();
        foreach ($products as $prod) {
            $items[] = array(
                'id_product' => (int)$prod['product_id'],
                'id_product_attribute' => (int)$prod['product_attribute_id'],
                'reference' => $prod['product_reference'],
                'quantity' => (int)$prod['product_quantity']
            );
        }

        $status_name = is_array($new_status->name) ? ($new_status->name[$id_lang] ?? reset($new_status->name)) : $new_status->name;

        $payload = array(
            'event' => 'order_status_update',
            'shop_id' => (int)$this->context->shop->id,
            'order_id' => (int)$order->id,
            'status_id' => (int)$new_status->id,
            'status_name' => $status_name,
            'products' => $items,
            'timestamp' => date('Y-m-d H:i:s')
        );

        $response = $this->sendWebhook($payload);

        foreach ($items as $item) {
            $this->logEvent('order_status_update', $item['reference'], $item['quantity'], (int)$new_status->id, $response['code'], $response['body']);
        }
    }

    public function hookActionUpdateQuantity($params)
    {
        if (!Configuration::get('STOCK_WEBHOOK_ACTIVE')) {
            return;
        }

        if (!isset($params['id_product'])) {
            return;
        }

        $id_product = (int)$params['id_product'];
        $id_product_attribute = isset($params['id_product_attribute']) ? (int)$params['id_product_attribute'] : 0;
        $quantity = (int)$params['quantity'];

        $reference = '';
        if ($id_product_attribute > 0) {
            $combination = new Combination($id_product_attribute);
            if (Validate::isLoadedObject($combination)) {
                $reference = $combination->reference;
            }
        }
        
        if (empty($reference)) {
            $product = new Product($id_product);
            if (Validate::isLoadedObject($product)) {
                $reference = $product->reference;
            }
        }

        $payload = array(
            'event' => 'stock_quantity_update',
            'shop_id' => (int)$this->context->shop->id,
            'id_product' => $id_product,
            'id_product_attribute' => $id_product_attribute,
            'reference' => $reference,
            'new_total_quantity' => $quantity,
            'timestamp' => date('Y-m-d H:i:s')
        );

        $response = $this->sendWebhook($payload);

        $this->logEvent('stock_quantity_update', $reference, $quantity, null, $response['code'], $response['body']);
    }

    private function sendWebhook($data)
    {
        $api_url = Configuration::get('STOCK_WEBHOOK_API_URL');
        $secret_key = Configuration::get('STOCK_WEBHOOK_SECRET');
        $json_data = json_encode($data);

        $ch = curl_init($api_url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($json_data),
            'X-PrestaShop-Secret-Key: ' . $secret_key
        ));

        $result = curl_exec($ch);
        $http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return array('code' => $http_code, 'body' => $result ? $result : '');
    }

    private function logEvent($event, $reference, $quantity, $status_id, $http_code, $response)
    {
        $truncated_response = Tools::substr($response, 0, 250);

        $data = array(
            'event_type' => pSQL($event),
            'reference' => pSQL($reference),
            'quantity' => (int)$quantity,
            'http_code' => (int)$http_code,
            'response' => pSQL($truncated_response),
            'date_add' => date('Y-m-d H:i:s')
        );

        if ($status_id !== null) {
            $data['status_id'] = (int)$status_id;
        }

        Db::getInstance()->insert('stock_webhook_log', $data);
    }
}