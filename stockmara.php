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

   private static $is_updating_internal_stock = false;
    public function __construct()
    {
        $this->name = 'stockmara';
        $this->tab = 'administration';
        $this->version = '1.3.0';
        $this->author = 'Winespiritus';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('StockMara');
        $this->description = $this->l('Gestão e registo de envios de stock (PS 1.7.6.2).');
        
        $this->ps_versions_compliancy = array('min' => '1.7.6.0', 'max' => '1.7.6.9');
    }

    public function install()
    {
        return parent::install() &&
            $this->createTables() &&
            Configuration::updateValue('STOCK_WEBHOOK_ACTIVE', 1) &&
            $this->registerHook('actionUpdateQuantity');
    }

    public function uninstall()
    {
        return parent::uninstall() &&
            $this->deleteTables() &&
            Configuration::deleteByName('STOCK_WEBHOOK_ACTIVE');
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

            Configuration::updateValue('STOCK_WEBHOOK_ACTIVE', $active);
            $output .= $this->displayConfirmation($this->l('✅ Alterações guardadas com sucesso!'));
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
                    )
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

public function hookActionUpdateQuantity($params) {
    // 1. IMPEDIR A EXECUÇÃO VIA CÓDIGO (Evita loop infinito entre as lojas)
    if (self::$is_updating_internal_stock) {
        return;
    }

    if (!Configuration::get('STOCK_WEBHOOK_ACTIVE')) {
        return;
    }

    $id_product = isset($params['id_product']) ? (int)$params['id_product'] : 0;
    $id_product_attribute = isset($params['id_product_attribute']) ? (int)$params['id_product_attribute'] : 0;
    $quantity = isset($params['quantity']) ? (int)$params['quantity'] : 0;

    if (!$id_product && !$id_product_attribute) {
        return;
    }

    // Identificar a loja de origem com segurança a partir dos parâmetros do hook
    $id_shop = isset($params['id_shop']) ? (int)$params['id_shop'] : (int)Context::getContext()->shop->id;
    if ($id_shop <= 0) {
        $id_shop = (int)Configuration::get('PS_SHOP_DEFAULT');
    }

    $id_lang = (int) Context::getContext()->language->id;
    
    // Proteção: Evitar ler propriedades de objetos nulos caso o Contexto falhe no AJAX
    $context_shop = Context::getContext()->shop;
    $name_shop = Validate::isLoadedObject($context_shop) ? $context_shop->name : 'Loja ' . $id_shop;

    $product_name = "n-a"; 

    if (!$id_product && $id_product_attribute > 0) {
        $id_product = (int) Product::getProductIdByAttribute($id_product_attribute);
    }

    if ($id_product > 0) {
        $product = new Product($id_product, false, $id_lang, $id_shop);
        if (Validate::isLoadedObject($product)) {
            $product_name = $product->name;
        }
    }

    // Executa a sincronização forçando o ID da loja detetada
    $this->atualizarStock($id_product, $id_product_attribute, $id_shop, $quantity);

    if (method_exists($this, 'logEvent')) {
        $this->logEvent('stock_quantity_update', $id_product, $name_shop, $product_name, $quantity);
    }
}

private function atualizarStock($id_product, $id_product_attribute, $id_shop, $quantity)
{
    // Se o ID da loja for inválido ou 0 (Todas as Lojas), não espelha
    if ($id_shop <= 0) {
        return false;
    }

    // Inversão matemática simples: se for 1 vai para 2, se for 2 vai para 1. Caso contrário sai.
    $id_shop_destino = ((int)$id_shop === 1) ? 2 : (((int)$id_shop === 2) ? 1 : 0);
    
    if ($id_shop_destino === 0) {
        return false;
    }

    // 1. Verifica se o produto existe na BD global
    if ($id_product > 0 && Product::existsInDatabase((int)$id_product, 'product')) {
        
        // Regressamos à tua query original (é a mais segura para o getValue do PrestaShop)
        $exists_in_dest_shop = Db::getInstance()->getValue(
            'SELECT id_product FROM '._DB_PREFIX_.'product_shop 
             WHERE id_product = '.(int)$id_product.' 
             AND id_shop = '.(int)$id_shop_destino
        );

        if (!$exists_in_dest_shop) {
            if (method_exists($this, 'logEvent')) {
                $this->logEvent('stock_sync_skip', $id_product, 'Aviso', 'Produto não associado à loja ' . $id_shop_destino, 0);
            }
            return false; 
        }

        $nova_quantidade = ($quantity < 0) ? 0 : $quantity;

        try {
            // Ativar o travão para bloquear chamadas em loop
            self::$is_updating_internal_stock = true;

            // Descobrir o ID de stock na loja destino
            $id_stock_available = (int)StockAvailable::getStockAvailableIdByProductId(
                (int)$id_product, 
                (int)$id_product_attribute, 
                (int)$id_shop_destino
            );

            if ($id_stock_available > 0) {
                $stockAvailable = new StockAvailable($id_stock_available);
                $stockAvailable->quantity = (int)$nova_quantidade;
                $stockAvailable->update();
            } else {
                $stockAvailable = new StockAvailable();
                $stockAvailable->id_product = (int)$id_product;
                $stockAvailable->id_product_attribute = (int)$id_product_attribute;
                $stockAvailable->id_shop = (int)$id_shop_destino;
                $stockAvailable->id_shop_group = 0; 
                $stockAvailable->quantity = (int)$nova_quantidade;
                $stockAvailable->add();
            }
            
        } catch (\Exception $e) {
            if (method_exists($this, 'logEvent')) {
                $this->logEvent('stock_sync_error', $id_product, 'Erro', $e->getMessage(), 0);
            }
            return false;
        } finally {
            // Desativar o travão SEMPRE
            self::$is_updating_internal_stock = false;
        }
        
        return true;
    }
    
    return false;
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