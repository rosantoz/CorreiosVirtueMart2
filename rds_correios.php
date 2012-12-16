<?php

/**
 * Este arquivo é um plugin para VirtueMart2 criado por
 * Rodrigo dos Santos (@rosantoz) que usa a classe RsCorreios criada pelo mesmo.
 * O plugin é totalmente gratuito e o código fonte está disponível no github:
 * http://github.com/rosantoz
 * Caso tenha interesse em fazer alguma melhoria abra um fork no projeto,
 * faça suas alterações e em seguida solicite um 'pull request'.
 *
 * Se este plugin lhe ajudou a economizar tempo ou dinheiro,
 * manifeste sua gratidão deixando um comentário no blog do autor.
 * Contribuições em dinheiro também serão bem recebidas:
 * http://www.rodrigodossantos.ws/?p=218
 *
 * PHP version 5.3.5
 *
 * @category VirtueMart2
 * @package  Vmshipment
 * @author   Rodrigo dos Santos <falecom@rodrigodossantos.ws>
 * @license  http://www.opensource.org/licenses/bsd-license.php BSD License
 * @version  GIT: <1.0>
 * @link     http://rodrigodossantos.ws
 *
 */

defined('_JEXEC') or die('Restricted access');

if (!class_exists('vmPSPlugin')) {
    include JPATH_VM_PLUGINS . DS . 'vmpsplugin.php';
}

require_once 'RsCorreios.php';

/**
 * Esta classe realiza o cálculo de frete dos correios.
 * (PAC, SEDEX, SEDEX 10 e SEDEX HOJE)
 *
 * Na primeira parte, que é a parte que foi alterada/criado,
 * a documentação está em português.
 * Na segunda parte foi mantida a documentação padrão do
 * VirtueMart em inglês.
 *
 * @category VirtueMart2
 * @package  Vmshipment
 * @author   Rodrigo dos Santos <falecom@rodrigodossantos.ws>
 * @license  http://www.opensource.org/licenses/bsd-license.php BSD License
 * @link     http://rodrigodossantos.wsr
 */
class plgVmShipmentRds_correios extends vmPSPlugin
{
    public static $_this = false;

    /**
     * Construtor da Classe
     *
     * @param object &$subject xx
     * @param array  $config   xx
     */
    function __construct(&$subject, $config)
    {
        parent::__construct($subject, $config);

        $this->_loggable   = true;
        $this->tableFields = array_keys($this->getTableSQLFields());
        $varsToPush        = $this->getVarsToPush();
        $this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
    }

    /**
     * Cria a tabela para o plugin caso ainda não exista.
     *
     * @return void
     */
    public function getVmPluginCreateTableSQL()
    {
        return $this->createTableSQL('Frete Correios by Rodrigo dos Santos');
    }

    /**
     * Retorna um array com as tabelas do plugin a serem criadas
     *
     * @return array
     */
    public function getTableSQLFields()
    {
        $SQLfields = array(
            'id'                           => 'int(1) UNSIGNED NOT NULL AUTO_INCREMENT',
            'virtuemart_order_id'          => 'int(11) UNSIGNED',
            'order_number'                 => 'char(32)',
            'virtuemart_shipmentmethod_id' => 'mediumint(1) UNSIGNED',
            'shipment_name'                => 'varchar(5000)',
            'order_weight'                 => 'decimal(10,4)',
            'shipment_weight_unit'         => 'char(3) DEFAULT \'LB\'',
            'shipment_cost'                => 'decimal(10,2)',
            'shipment_package_fee'         => 'decimal(10,2)',
            'tax_id'                       => 'smallint(1)'
        );
        return $SQLfields;
    }


    /**
     * Obtém a largura total dos produtos que estão no pedido
     *
     * @param VirtueMartCart $cart Objeto do tipo VirtueMartCart
     *
     * @return int
     */
    protected function getOrderWidth(VirtueMartCart $cart)
    {
        $width = 0;
        foreach ($cart->products as $product) {
            $width += ($product->product_width * $product->quantity);
        }
        return $width;
    }

    /**
     * Obtém a altura total dos produtos que estão no pedido
     *
     * @param VirtueMartCart $cart Objeto do tipo VirtueMartCart
     *
     * @return int
     */
    protected function getOrderHeight(VirtueMartCart $cart)
    {
        $height = 0;
        foreach ($cart->products as $product) {
            $height += ($product->product_height * $product->quantity);
        }
        return $height;
    }

    /**
     * Obtém o comprimento total dos produtos que estão no pedido
     *
     * @param VirtueMartCart $cart Objeto do tipo VirtueMartCart
     *
     * @return int
     */
    protected function getOrderLength(VirtueMartCart $cart)
    {
        $length = 0;
        foreach ($cart->products as $product) {
            $length += ($product->product_length * $product->quantity);
        }
        return $length;
    }

    /**
     * Obtém o peso total dos produtos que estão no pedido
     *
     * @param VirtueMartCart $cart Objeto do tipo VirtueMartCart
     *
     * @return float|int
     */
    protected function getOrderWeight(VirtueMartCart $cart)
    {

        $weight = 0;
        foreach ($cart->products as $product) {
            $weight += ($product->product_weight * $product->quantity);
        }
        return $weight;
    }

    /**
     * Verifica se a opção Valor Declarado deve ou não ser usada
     *
     * @param Obj   $method      Objeto com as configurações do plugin
     * @param array $cart_prices Array com as informações do carrinho
     *
     * @return int
     */
    protected function getDeclaredValue($method, $cart_prices)
    {
        if ($method->valorDeclarado == '1') {
            return $cart_prices['salesPrice'];
        }

        return 0;
    }

    /**
     * Verifica se a opção "Mão Própria" deve ou não ser usada
     *
     * @param Obj $method Objeto com as configurações do plugin
     *
     * @return bool
     */
    protected function getOwnHand($method)
    {
        if ($method->maoPropria == '1') {
            return true;
        }
        return false;
    }

    /**
     * Verifica se a opção "Aviso de Recebimento" deve ou não ser usada
     *
     * @param Obj $method Objeto com as configurações do plugin
     *
     * @return bool
     */
    protected function getDeliveryWarning($method)
    {
        if ($method->avisoRecebimento == '1') {
            return true;
        }
        return false;
    }

    /**
     * Utiliza os métodos da classe RsCorreios para se comunicar
     * com o WS dos correios e obter as informações do frete
     *
     * @param VirtueMartCart $cart        Objeto com as informações do carrinho
     * @param Obj            $method      Objeto com as configurações do plugin
     * @param array          $cart_prices Array com as informações do carrinho
     *
     * @return array
     */
    protected function getRsCorreiosResponse(VirtueMartCart $cart, $method, $cart_prices)
    {
        $shipment = new RsCorreios();
        $response = $shipment
            ->setCepOrigem($method->cepOrigem)
            ->setCepDestino($cart->ST['zip'])
            ->setLargura($this->getOrderWidth($cart))
            ->setComprimento($this->getOrderLength($cart))
            ->setAltura($this->getOrderHeight($cart))
            ->setPeso($this->getOrderWeight($cart))
            ->setFormatoDaEncomenda($method->formato)
            ->setServico($method->servico)
            ->setValorDeclarado($this->getDeclaredValue($method, $cart_prices))
            ->setMaoPropria($this->getOwnHand($method))
            ->setAvisoDeRecebimento($this->getDeliveryWarning($method))
            ->dados();

        return $response;
    }

    /**
     * Efetua o cálculo do frete
     *
     * @param VirtueMartCart $cart        Objeto com as informações do carrinho
     * @param Obj            $method      Objeto com as configurações do plugin
     * @param array          $cart_prices Array com as informações do carrinho
     *
     * @return int|string
     */
    function getCosts(VirtueMartCart $cart, $method, $cart_prices)
    {
        $frete = $this->getRsCorreiosResponse($cart, $method, $cart_prices);
        return $frete['valor'];

    }

    /**
     * Verifica se as condições para o cálculo do frete foram atendidas
     * para que a opção apareça para o usuário
     *
     * @param VirtueMartCart $cart        Objeto com as informações do carrinho
     * @param Obj            $method      Objeto com as configurações do plugin
     * @param array          $cart_prices Array com as informações do carrinho
     *
     * @return bool
     */
    protected function checkConditions(VirtueMartCart $cart, $method, $cart_prices)
    {
        $zipCode = empty($cart->ST['zip']) ? false : true;

        // CEP não informado
        if (!$zipCode) {
            vmWarn('RDS_CORREIOS_INFO_ENDERECO_NAO_INFORMADO');
            return false;
        }

        // Erro do WS dos correios
        $frete = $this->getRsCorreiosResponse($cart, $method, $cart_prices);
        //var_dump($frete);
        if ($frete['erro'] != 0) {
            vmWarn($frete['msgErro']);
            return false;
        }

        $country = $cart->ST['virtuemart_country_id'];

        if (($zipCode == true) && ($country == '30')) {
            return true;
        }

        return false;
    }

    /**
     * SEGUNDA PARTE
     * Daqui para baixo é o código original do VirtueMart
     */

    /**
     * This event is fired after the order has been stored; it gets the shipment method-
     * specific data.
     *
     * @param int $order_id The order_id being processed
     * @param object $cart  the cart
     * @param array $priceData Price information for this order
     * @return mixed Null when this method was not selected, otherwise true
     * @author Valerie Isaksen
     */
    function plgVmConfirmedOrder(VirtueMartCart $cart, $order)
    {
        if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_shipmentmethod_id))) {
            return null; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($method->shipment_element)) {
            return false;
        }
        $values['virtuemart_order_id']          = $order['details']['BT']->virtuemart_order_id;
        $values['order_number']                 = $order['details']['BT']->order_number;
        $values['virtuemart_shipmentmethod_id'] = $order['details']['BT']->virtuemart_shipmentmethod_id;
        $values['shipment_name']                = $this->renderPluginName($method);
        $values['order_weight']                 = $this->getOrderWeight($cart, $method->weight_unit);
        $values['shipment_weight_unit']         = $method->weight_unit;
        $values['shipment_cost']                = $method->cost;
        $values['shipment_package_fee']         = $method->package_fee;
        $values['tax_id']                       = $method->tax_id;
        $this->storePSPluginInternalData($values);

        return true;
    }

    /**
     * This method is fired when showing the order details in the frontend.
     * It displays the shipment-specific data.
     *
     * @param integer $order_number The order Number
     * @return mixed Null for shipments that aren't active, text (HTML) otherwise
     * @author Valérie Isaksen
     * @author Max Milbers
     */
    public function plgVmOnShowOrderFEShipment($virtuemart_order_id, $virtuemart_shipmentmethod_id, &$shipment_name)
    {
        $this->onShowOrderFE($virtuemart_order_id, $virtuemart_shipmentmethod_id, $shipment_name);
    }

    /**
     * Create the table for this plugin if it does not yet exist.
     * This functions checks if the called plugin is active one.
     * When yes it is calling the standard method to create the tables
     * @author Valérie Isaksen
     *
     */
    function plgVmOnStoreInstallShipmentPluginTable($jplugin_id)
    {
        return $this->onStoreInstallPluginTable($jplugin_id);
    }

    /**
     * This event is fired after the shipment method has been selected. It can be used to store
     * additional payment info in the cart.
     *
     * @author Max Milbers
     * @author Valérie isaksen
     *
     * @param VirtueMartCart $cart: the actual cart
     * @return null if the payment was not selected, true if the data is valid, error message if the data is not vlaid
     *
     */
    // public function plgVmOnSelectCheck($psType, VirtueMartCart $cart) {
    // return $this->OnSelectCheck($psType, $cart);
    // }
    public function plgVmOnSelectCheckShipment(VirtueMartCart &$cart)
    {
        return $this->OnSelectCheck($cart);
    }

    /**
     * plgVmDisplayListFE
     * This event is fired to display the pluginmethods in the cart (edit shipment/payment) for exampel
     *
     * @param object $cart Cart object
     * @param integer $selected ID of the method selected
     * @return boolean True on succes, false on failures, null when this plugin was not selected.
     * On errors, JError::raiseWarning (or JError::raiseError) must be used to set a message.
     *
     * @author Valerie Isaksen
     * @author Max Milbers
     */
    public function plgVmDisplayListFEShipment(VirtueMartCart $cart, $selected = 0, &$htmlIn)
    {
        return $this->displayListFE($cart, $selected, $htmlIn);
    }

    /*
     * plgVmonSelectedCalculatePrice
     * Calculate the price (value, tax_id) of the selected method
     * It is called by the calculator
     * This function does NOT to be reimplemented. If not reimplemented, then the default values from this function are taken.
     * @author Valerie Isaksen
     * @cart: VirtueMartCart the current cart
     * @cart_prices: array the new cart prices
     * @return null if the method was not selected, false if the shiiping rate is not valid any more, true otherwise
     *
     *
     */

    public function plgVmonSelectedCalculatePriceShipment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name)
    {
        return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
    }

    /**
     * plgVmOnCheckAutomaticSelected
     * Checks how many plugins are available. If only one,
     * the user will not have the choice. Enter edit_xxx page
     * The plugin must check first if it is the correct type
     * @author Valerie Isaksen
     * @param VirtueMartCart cart: the cart object
     * @return null if no plugin was found, 0 if more then one plugin was found,  virtuemart_xxx_id if only one plugin is found
     *
     */
    public function plgVmOnCheckAutomaticSelectedShipment(
        VirtueMartCart $cart,
        array $cart_prices = array())
    {
        return $this->onCheckAutomaticSelected($cart, $cart_prices
        );
    }

    /**
     * This method is fired when showing when priting an Order
     * It displays the the payment method-specific data.
     *
     * @param integer $order_number The order ID
     * @param integer $method_id    method used for this order
     *
     * @return mixed Null when for payment methods that were
     * not selected, text (HTML) otherwise
     * @author Valerie Isaksen
     */
    public function plgVmonShowOrderPrint($order_number, $method_id)
    {
        return $this->onShowOrderPrint($order_number, $method_id);
    }

    /**
     * Missing doc
     *
     * @param string  $name  name
     * @param integer $id    id
     * @param string  &$data data
     *
     * @return bool
     */
    public function plgVmDeclarePluginParamsShipment($name, $id, &$data)
    {
        return $this->declarePluginParams('shipment', $name, $id, $data);
    }

    /**
     * Missing doc
     *
     * @param string  $name   name
     * @param integer $id     id
     * @param string  &$table table
     *
     * @return bool
     */
    public function plgVmSetOnTablePluginParamsShipment($name, $id, &$table)
    {
        return $this->setOnTablePluginParams($name, $id, $table);
    }

}