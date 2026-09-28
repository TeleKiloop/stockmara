<?php
/**
 * Módulo de Integração de Stock com Intranet
 * Compatível especificamente com PrestaShop 1.7.6.2
 */



/**
 * 
 * Falta na API retornar o nome do produto para facilitar o registo no log. Atualmente, o nome do produto não é retornado na resposta da API.
 * 
 * Criar sistema que ao clicar no ID do produto no log, abra a página de edição do produto no backoffice do PrestaShop.
 * 
 * 
 * 
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class StockMara extends Module
{
    public function __construct()
    {
        $this->name = 'stockmara';
        $this->tab = 'administration';
        $this->version = '1.2.2';
        $this->author = 'Winespiritus';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('StockMara');
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
            `id_product` VARCHAR(50) DEFAULT NULL,
            `name_shop` VARCHAR(50) DEFAULT NULL,
            `name_product` VARCHAR(50) DEFAULT NULL,
            `quantity` INT(11) DEFAULT NULL,
            `status_id` INT(11) DEFAULT NULL,
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
        if (Tools::isSubmit('submitStockMaraConfig')) {
            $active = (int)Tools::getValue('STOCK_WEBHOOK_ACTIVE');
            $api_url = trim(Tools::getValue('STOCK_WEBHOOK_API_URL'));
            $secret = trim(Tools::getValue('STOCK_WEBHOOK_SECRET'));

            if (empty($api_url) || !Validate::isUrl($api_url)) {
                $output .= $this->displayError($this->l('Por favor introduza um URL válido.'));
            } else {
                Configuration::updateValue('STOCK_WEBHOOK_ACTIVE', $active);
                Configuration::updateValue('STOCK_WEBHOOK_API_URL', $api_url);
                Configuration::updateValue('STOCK_WEBHOOK_SECRET', $secret);

                $output .= $this->displayConfirmation($this->l('✅ Alterações guardadas com sucesso!'));
            }
        }

        return $output . $this->renderForm() . $this->renderLogList();
    }

    public function renderForm()
    {
        $fields_form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Configurações básicas do módulo'),
                    'icon' => 'icon-cogs'
                ),
                'input' => array(
                    array(
                        'type' => 'switch',
                        'label' => $this->l('O modulo está ativo?'),
                        'name' => 'STOCK_WEBHOOK_ACTIVE',
                        'is_bool' => true,
                        'values' => array(
                            array('id' => 'active_on', 'value' => 1, 'label' => $this->l('Sim')),
                            array('id' => 'active_off', 'value' => 0, 'label' => $this->l('Não'))
                        ),
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('URL da API'),
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
                    'name' => 'submitStockMaraConfig', // CORRIGIDO: Nome do botão de submissão
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
        $helper->submit_action = 'submitStockMaraConfig'; // CORRIGIDO: Ação explícita do formulário

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
            'id_product' => array('title' => $this->l('ID Produto'), 'type' => 'int', 'align' => 'center'),
            'name_shop' => array('title' => $this->l('Loja'), 'type' => 'text'),
            'name_product' => array('title' => $this->l('Nome do Produto'), 'type' => 'text'),
            'quantity' => array('title' => $this->l('Qtd Enviada'), 'align' => 'center'),
            'date_add' => array('title' => $this->l('Data / Hora'), 'type' => 'datetime'),
        );

        $helper = new HelperList();
        $helper->shopLinkType = '';
        $helper->simple_header = false;
        $helper->identifier = 'id_log';
        $helper->actions = array();
        $helper->show_toolbar = false;
        $helper->title = $this->l('Histórico de Comunicação de Stocks (últimos 100 registos)');
        $helper->table = 'stock_webhook_log';
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;

        $sql = 'SELECT * FROM `' . _DB_PREFIX_ . 'stock_webhook_log` ORDER BY `id_log` DESC LIMIT 100';
        $logs = Db::getInstance()->executeS($sql);

        return $helper->generateList($logs ? $logs : array(), $fields_list);
    }

    public function hookActionUpdateQuantity($params)
    {
        if (!Configuration::get('STOCK_WEBHOOK_ACTIVE')) {
            return;
        }

        if (!isset($params['id_product'])) {
            return;
        }

        // 
        $id_product = (int)$params['id_product'];
        $id_product_attribute = isset($params['id_product_attribute']) ? (int)$params['id_product_attribute'] : 0;
        $quantity = (int)$params['quantity'];

        $id_product_final = $id_product ? $id_product : $id_product_attribute;

        $id_lang = (int) Context::getContext()->language->id;
        $id_shop = (int) Context::getContext()->shop->id;
        $name_shop = Context::getContext()->shop->name;

        $product_name = "";
        if ($id_product) {
            $product = new Product($id_product, false, $id_lang, $id_shop);
            if (Validate::isLoadedObject($product)) {
                $product_name = $product->name;
            }
        } elseif ($id_product_attribute) {
            $id_product = (int) Product::getProductIdByAttribute($id_product_attribute);

            if ($id_product > 0) {
                // 2. Instanciar o produto para obter o nome
                $product = new Product($id_product, false, $id_lang);
                
                if (Validate::isLoadedObject($product)) {
                    $product_name = $product->name;
                }
            }
        }
       

        $this->logEvent('stock_quantity_update', $id_product_final, $name_shop, $product_name, $quantity);
    }

    private function logEvent($event, $id_product, $name_shop, $name_product, $quantity)
    {

        $data = array(
            'event_type' => pSQL($event),
            'id_product' => (int)$id_product,
            'name_shop' => pSQL($name_shop),
            'name_product' => pSQL($name_product),
            'quantity' => (int)$quantity,
            'date_add' => date('Y-m-d H:i:s')
        );

        Db::getInstance()->insert('stock_webhook_log', $data);
    }
}