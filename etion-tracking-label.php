<?php
/**
 *
 * Plugin Name: Etion - Etiquetas dos correios (Sigep)
 * Description: Cria etiquetas dos Sigep para despachar os produtos
 * Author: Etion Digital
 * Plugin URI: https://etion.digital
 * Version: 1.0.2
*/
if (!defined('ABSPATH')) {
    die;
}

if (is_admin())
{
    new Tracking_Labels();
}

abstract class Sigep_Abstract
{
    //PRODUÇÃO
    public $webserviceURL = 'https://apps.correios.com.br/SigepMasterJPA/AtendeClienteService/AtendeCliente?wsdl';
    //homologacao
    //public $webserviceURL = 'https://apphom.correios.com.br/SigepMasterJPA/AtendeClienteService/AtendeCliente?wsdl';
    

    public function getConfiguracaoEtiqueta() {
        $autoload = __DIR__ . '/php-sigep/vendor/autoload.php';

        if (file_exists($autoload)) {
            require_once $autoload;
        }

        if (!class_exists('PhpSigepFPDF')) {
            throw new RuntimeException(
                'Não encontrei a classe PhpSigepFPDF. Execute "php composer.phar install" ou baixe o projeto ' .
                'https://github.com/stavarengo/php-sigep-fpdf manualmente e adicione a classe no seu path.'
            );
        }

        //require_once __DIR__ . '/php-sigep/src/PhpSigep/Bootstrap.php';
        //ini_set("soap.wsdl_cache_enabled", 0);
        $config = new \PhpSigep\Config();
        //PRODUÇÃO
        $config->setEnv(\PhpSigep\Config::ENV_PRODUCTION);
        //homologacao
        //$config->setEnv(\PhpSigep\Config::ENV_DEVELOPMENT);
        //$config->setWsdlAtendeCliente('AtendeCliente.xml');
        $config->setCacheOptions(
            array(
                'storageOptions' => array(
                    'enabled' => false,
                    'ttl' => 10,
                    'cacheDir' => sys_get_temp_dir(),
                ),
            )
        );

        \PhpSigep\Bootstrap::start($config);
    }

    public function getDimensao($order) {
        $total_width = 0;
        $total_height = 0;
        $total_length = 0;

        foreach( $order->get_items() as $item ){
            $product_id = $item->get_product_id();
            $_pf = new WC_Product_Factory();  
            $_product = $_pf->get_product($product_id);
            $quantity = $item->get_quantity();
            for ($i=0; $i < $quantity; $i++) { 
                $total_width  += (float)$_product->get_width();
                $total_height += (float)$_product->get_height();
                $total_length += (float)$_product->get_length();
            }
        }

        $largura_total = str_replace('.', ',', number_format($total_width, 2));
        $altura_total = str_replace('.', ',', number_format($total_height, 2));
        $comprimento_total = str_replace('.', ',', number_format($total_length, 2));

        $dimensao = new \PhpSigep\Model\Dimensao();
        $dimensao->setLargura($largura_total);
        $dimensao->setAltura($altura_total);
        $dimensao->setComprimento($comprimento_total);
        $dimensao->setDiametro(0);
        $dimensao->setTipo(\PhpSigep\Model\Dimensao::TIPO_PACOTE_CAIXA);
        return $dimensao;
    }

    public function getDestinatario($order) {
        $order_data = $order->get_data();
        $order_shipping_first_name = $order_data['shipping']['first_name'];
        $order_shipping_last_name = $order_data['shipping']['last_name'];
        $order_shipping_address_1 = $order_data['shipping']['address_1'];
        $order_shipping_address_2 = $order_data['shipping']['address_2'];
        $order_shipping_number = $order->get_meta('_shipping_number');
        $order_shipping_neighborhood = $order->get_meta('_shipping_neighborhood');
        $order_shipping_postcode = $order_data['shipping']['postcode'];
        $order_shipping_city = $order_data['shipping']['city'];
        $order_shipping_state = $order_data['shipping']['state'];

        $destinatario = new \PhpSigep\Model\Destinatario();
        $destinatario->setNome("{$order_shipping_first_name} {$order_shipping_last_name}");
        $destinatario->setLogradouro($order_shipping_address_1);
        $destinatario->setNumero($order_shipping_number);
        $destinatario->setComplemento($order_shipping_address_2);
        $destinatario->setBairro($order_shipping_neighborhood);
        $destinatario->setCep($order_shipping_postcode);
        $destinatario->setCidade($order_shipping_city);
        $destinatario->setUf($order_shipping_state);
        
        return $destinatario;
    }

    public function getDestinoNacional($order) {
        $order_shipping_neighborhood = $order->get_meta( '_shipping_neighborhood' );
        $order_shipping_postcode = $order->get_shipping_postcode();            
        $order_shipping_city = $order->get_shipping_city();
        $order_shipping_state = $order->get_shipping_state();
        $destino = new \PhpSigep\Model\DestinoNacional();
        $destino->setBairro($order_shipping_neighborhood);
        $destino->setCep($order_shipping_postcode);
        $destino->setCidade($order_shipping_city);
        $destino->setUf($order_shipping_state);
        return $destino;
    }

    public function getServicoAdicional($order) {
        $servicoAdicional = new \PhpSigep\Model\ServicoAdicional();
        $servicoAdicional->setCodigoServicoAdicional(\PhpSigep\Model\ServicoAdicional::SERVICE_REGISTRO);
        $servicoAdicional->setValorDeclarado(0);
        return $servicoAdicional;
    }

    public function getEncomenda(
        $order,
        $servicoAdicional,
        $destinatario,
        $destino,
        $dimensao,
        $etiqueta,
        $servicoPostagem
    ) {
        $encomenda = new \PhpSigep\Model\ObjetoPostal();
        $encomenda->setServicosAdicionais(array($servicoAdicional));
        $encomenda->setDestinatario($destinatario);
        $encomenda->setDestino($destino);
        $encomenda->setDimensao($dimensao);
        $encomenda->setEtiqueta($etiqueta);

        $total_weight = 0;

        foreach( $order->get_items() as $item ){
            $product_id = $item->get_product_id();
            $_pf = new WC_Product_Factory();  
            $_product = $_pf->get_product($product_id);
            $total_weight  += (float)$_product->get_weight();
        }

        $encomenda->setPeso($total_weight);
        $encomenda->setServicoDePostagem(new \PhpSigep\Model\ServicoDePostagem($servicoPostagem));
        return $encomenda;
    }

    public function getRemetente() {
        $store_name = get_bloginfo('name');

        $store_address     = get_option( 'woocommerce_store_address' );
        $store_number     = get_option( 'woocommerce_store_address_number' );
        $store_address_2   = get_option( 'woocommerce_store_address_2' );
        $store_neighborhood  = get_option( 'woocommerce_store_neighborhood' );

        $store_city        = get_option( 'woocommerce_store_city' );
        $store_postcode    = get_option( 'woocommerce_store_postcode' );

        $store_raw_country = get_option( 'woocommerce_default_country' );

        $store_cnpj = esc_attr( get_option( 'etiqueta_correios_cnpj', '' ) );

        $split_country = explode( ":", $store_raw_country );
        $store_state   = $split_country[1];

        $remetente = new \PhpSigep\Model\Remetente();
        $remetente->setNome($store_name );
        $remetente->setLogradouro($store_address);
        $remetente->setNumero($store_number);
        $remetente->setComplemento($store_address_2);
        $remetente->setBairro($store_neighborhood);
        $remetente->setCep($store_postcode);
        $remetente->setCidade($store_city);
        $remetente->setUf($store_state);
        $remetente->setIdentificacao($store_cnpj);
        return $remetente;
    }

    public function getDigitoVerificador($etiqueta_id) {
        $payload = [];
        $payload['usuario'] = esc_attr( get_option( 'etiqueta_correios_login', '' ) );
        $payload['senha'] = esc_attr( get_option( 'etiqueta_correios_senha', '' ) );
        $payload['codigo_administrativo'] = esc_attr( get_option( 'etiqueta_correios_codigo_administrativo', '' ) );
        $payload['etiquetas'] = $etiqueta_id;
        $client = new SoapClient($this->webserviceURL);

        return $client->geraDigitoVerificadorEtiquetas($payload)->return;
    }

    public function getAccessData() {
        $accessData = new \PhpSigep\Model\AccessData();
        $cartao_postagem = esc_attr( get_option( 'etiqueta_correios_cartao_postagem', '' ) );
        $numero_contrato = esc_attr( get_option( 'etiqueta_correios_numero_contrato', '' ) );
        $ano = esc_attr( get_option( 'etiqueta_correios_ano', '' ) );
        $usuario = esc_attr( get_option( 'etiqueta_correios_login', '' ) );
        $senha = esc_attr( get_option( 'etiqueta_correios_senha', '' ) );
        $cnpj = esc_attr( get_option( 'etiqueta_correios_cnpj', '' ) );
        $codigo_administrativo = esc_attr( get_option( 'etiqueta_correios_codigo_administrativo', '' ) );
        $accessData->setNumeroContrato($numero_contrato);
        $accessData->setCartaoPostagem($cartao_postagem);
        $accessData->setAnoContrato($ano);
        $accessData->setCnpjEmpresa($cnpj);
        $accessData->setCodAdministrativo($codigo_administrativo);
        $accessData->setUsuario($usuario);
        $accessData->setSenha($senha);
        $accessData->setIdCorreiosUsuario($usuario);
        $accessData->setIdCorreiosSenha($senha);
        $diretoria_sigep = esc_attr(get_option( 'diretoria_sigep', '' ));
        $diretoria = new \PhpSigep\Model\Diretoria($diretoria_sigep);
        $accessData->setDiretoria($diretoria);
        return $accessData;
    }

    public function getEtiquetaSemDv($etiquetaSemDv) {
        $etiqueta = new \PhpSigep\Model\Etiqueta();
        $etiqueta->setEtiquetaSemDv($etiquetaSemDv);
        return $etiqueta;
    } 
    
}

/**
 * Tracking_Labels_Wp_List_Table class will create the page to load the table
 */
class Tracking_Labels extends Sigep_Abstract
{
    /** 
     * Constructor will create the menu item
     */
    //PRODUÇÃO
     //public $webserviceURL = 'https://apps.correios.com.br/SigepMasterJPA/AtendeClienteService/AtendeCliente?wsdl';
    //homologacao

    public function __construct() {
        add_action( 'admin_menu', array($this, 'tracking_labels_list' ));
        add_action( 'manage_shop_order_posts_custom_column' , array($this, 'custom_orders_list_column_content'), 20, 2 );
       
        add_filter( 'manage_edit-shop_order_columns', array($this, 'custom_shop_order_column'), 20 );
        add_filter( 'bulk_actions-edit-shop_order', array($this, 'sigep_bulk_actions') );
        add_filter( 'handle_bulk_actions-edit-shop_order', array($this, 'sigep_bulk_action_handler'), 10, 3 );
        add_action('admin_notices',         array($this, 'custom_bulk_admin_notices'), 10000);
    }
    
    function custom_bulk_admin_notices() {
        global $post_type, $pagenow;
        
        if($pagenow == 'edit.php' && $post_type == 'post' && isset($_REQUEST['exported']) && (int) $_REQUEST['exported']) {
            $message = sprintf( _n( 'Post exported.', '%s posts exported.', $_REQUEST['exported'] ), number_format_i18n( $_REQUEST['exported'] ) );
            echo "<div class=\"updated\"><p>{$message}</p></div>";
        }
    }

    public function sigep_bulk_action_handler( $redirect, $doaction, $object_ids ) {
        $redirect = remove_query_arg( array( 'sigep_done' ), $redirect );
        if ( $doaction == 'gerar_plp' ) {
            ob_end_clean();
            header('Content-Type: text/html; charset=utf-8');
            $etiquetasXml = '';
            $idPlpCliente = "";
            $cartaoPostagem = esc_attr(get_option('etiqueta_correios_cartao_postagem', ''));
            $usuario = esc_attr( get_option( 'etiqueta_correios_login', '' ) );
            $senha = esc_attr( get_option( 'etiqueta_correios_senha', '' ) );
            $this->getConfiguracaoEtiqueta();
            $accessData = $this->getAccessData();
            $encomendas = [];
                                                                 
            $logoFile = wp_get_original_image_path(get_option('etiqueta_correios_logomarca', ''));

            $identificador = esc_attr( get_option( 'etiqueta_correios_cnpj', '' ) );

            $store_raw_country = get_option( 'woocommerce_default_country' );

            $split_country = explode( ":", $store_raw_country );
            $store_state   = $split_country[1];

            $upload_dir = wp_get_upload_dir();

            $valid_orders = []; 
            foreach ( $object_ids as $post_id ) {
                $order = wc_get_order(intval($post_id));
                $idServico = false;
                foreach ($order->get_items('shipping') as $item) {
                    $idServico = trim(get_option( "chancela_{$item->get_instance_id()}", '' ));
                }

                if ($idServico) {
                    $servico_split = explode('|', $idServico);

                    $valid_orders[$servico_split[0]][] = $order;
                } 
            }

            $etiquetasSemDv = [];

            $remetente = $this->getRemetente();

            foreach ($valid_orders as $servico => $servicos_orders) {
                $quantidade_etiquetas = count($servicos_orders);
                $idServico = false;
                foreach ($order->get_items('shipping') as $item) {
                    $idServico = trim(get_option( "chancela_{$item->get_instance_id()}", '' ));
                }

                $servico_split = explode('|', $idServico);
                $servicoPostagem = $servico_split[1];
                $tipoChancela = $servico_split[2];

                $dadosEtiqueta = new \PhpSigep\Model\SolicitaEtiquetas();
                $dadosEtiqueta->setQtdEtiquetas($quantidade_etiquetas);
                $dadosEtiqueta->setServicoDePostagem(new \PhpSigep\Model\ServicoDePostagem($servicoPostagem));
                $dadosEtiqueta->setAccessData($accessData);
                $dadosEtiqueta->setModoUmaRequisicao();

                $phpSigep = new PhpSigep\Services\SoapClient\Real();

                $etiquetas = $phpSigep->solicitaEtiquetas($dadosEtiqueta)->getResult();
                $encomendas = [];

                foreach ( $servicos_orders as $key => $order ) {
                    $dimensao = $this->getDimensao($order);
                    $destinatario = $this->getDestinatario($order);
                    $destino = $this->getDestinoNacional($order);
                    $etiqueta = $etiquetas[$key];
                    $servicoAdicional = $this->getServicoAdicional($order);

                    $encomendas[] = $this->getEncomenda(
                        $order,
                        $servicoAdicional,
                        $destinatario,
                        $destino,
                        $dimensao,
                        $etiqueta,
                        $servicoPostagem
                    );
                }

                $plp = new \PhpSigep\Model\PreListaDePostagem();
                $plp->setAccessData($accessData);
                $plp->setEncomendas($encomendas);
                $plp->setRemetente($remetente);
                $phpSigep = new PhpSigep\Services\SoapClient\Real(); 
                $sigep = $phpSigep->fechaPlpVariosServicos($plp)->getResult();
                $layoutChancela = array(strtolower($tipoChancela));
                $filenameEtiqueta = 'etiquetas_'.$sigep->getIdPlp().'.pdf';
                $pdf = new \PhpSigep\Pdf\CartaoDePostagem($plp, time(), $logoFile, $layoutChancela);
                $pdf->render('F', $upload_dir['path'].'/'.$filenameEtiqueta);

                foreach ($servicos_orders as $key => $order) {
                    $order_id = $order->get_id();
                    $plp_id = $sigep->getIdPlp();
                    update_option('pedido_etiqueta_'.$order_id , $plp_id); 
                }
                
                header("Content-type:application/pdf");

                header("Content-Disposition:attachment;filename={$filenameEtiqueta}");

                readfile($upload_dir['path'].'/'.$filenameEtiqueta);

                die;
            }    
        }
        return $redirect;
    }

    public function sigep_bulk_actions( $bulk_array ) {
    
        $bulk_array['gerar_plp'] = 'Gerar PLP';
        return $bulk_array;
    
    }

    public function get_file_url( $file = __FILE__ ) {
        $file_path = str_replace( "\\", "/", str_replace( str_replace( "/", "\\", WP_CONTENT_DIR ), "", $file ) );
        if ( $file_path )
            return content_url( $file_path );
        return false;
    }
    
    public function consultaCep($cep) {
        $payload = ['cep' => $cep];
        $client = new SoapClient($this->webserviceURL);

        return $client->consultaCEP($payload)->return;
    }

    public function verificaDisponibilidadeServico($cepDestino, $numeroServico) {
        $cepOrigem = get_option( 'woocommerce_store_postcode' );
        $cepOrigem = str_replace('-', '', $cepOrigem);
        
        $usuario = esc_attr( get_option( 'etiqueta_correios_login', '' ) );
        $senha = esc_attr( get_option( 'etiqueta_correios_senha', '' ) );
        $codAdministrativo = esc_attr( get_option( 'etiqueta_correios_codigo_administrativo', '' ) );

        $payload = [
            'codAdministrativo' => $codAdministrativo,
            'numeroServico' => $numeroServico,
            'cepOrigem' => $cepOrigem,
            'cepDestino' => $cepDestino,
            'usuario' => $usuario,
            'senha' => $senha
        ];
        $client = new SoapClient($this->webserviceURL);

        return $client->verificaDisponibilidadeServico($payload)->return;
    }

    public function custom_orders_list_column_content( $column, $post_id ) {
        if ($column === 'criar-etiqueta-sigep') {
            $order = wc_get_order($post_id);
            $is_sigep = false;
            foreach ($order->get_items('shipping') as $item) {
                $chancela = trim(get_option( "chancela_{$item->get_instance_id()}", '' ));

                if ($chancela !== '') {
                    $is_sigep = true;
                    break;
                }
            }

            if ($is_sigep) {
                $order_id = $order->get_id();

                $pedido_etiqueta = get_option('pedido_etiqueta_'.$order_id, '');

                $gerarEtiquetaHTML = '<div id="pedido_etiqueta_'.$order_id.'" ></div>Não possui etiqueta';

                if ($pedido_etiqueta !== '') {
                    $upload_dir = wp_get_upload_dir();
                    $gerarEtiquetaHTML = '<div id="pedido_etiqueta_'.$order_id.'" >PLP '.$pedido_etiqueta.' <a href="'.$upload_dir['url'].'/etiquetas_'.$pedido_etiqueta.'.pdf" target="_blank">Ver</a></div>';
                }

                echo '<p>
                        <script>
                        function gerarEtiqueta'.$post_id.'() {
                            if (jQuery("#etiqueta_'.$post_id.'").html() !== "Processando") {
                                jQuery("#etiqueta_'.$post_id.'").html("Processando");
                                jQuery.ajax({
                                    url: "/wp-json/gerar-etiqueta/v1/pedido/'.$post_id.'",
                                    success: function(data) {
                                        if (data.status && "undefined" !== typeof data.etiqueta) {
                                            alert("Etiqueta gerada com sucesso: " + data.etiqueta);
                                            jQuery("#pedido_etiqueta_'.$order_id.'").html(data.etiqueta);
                                            jQuery("#etiqueta_'.$post_id.'").html("Renovar etiqueta");
                                        } else {
                                            alert("Houve um erro ao gerar a etiqueta.");
                                            jQuery("#etiqueta_'.$post_id.'").html("Criar etiqueta");
                                        }
                                    }
                                });
                            } 
                            return false;
                        }
                        </script>
                        '.$gerarEtiquetaHTML.'
                    </p>';
            } else {
                echo '<p>Frete não integrado com SIGEP</p>';
            }
        }
    }

    public function custom_shop_order_column($columns) {
        $reordered_columns = array();

        foreach( $columns as $key => $column){
            $reordered_columns[$key] = $column;
            if( $key ==  'order_status' ){
                $reordered_columns['criar-etiqueta-sigep'] = __( 'Criar Etiqueta Sigep','theme_domain');
            }
        }
        return $reordered_columns;
    }

    public function tracking_labels_list() {
        add_menu_page( 'Tracking Label Plugin', 'Etiquetas dos Correios', 'manage_options', 'tracking-label-plugin', array($this, 'dadosSigep'), 'dashicons-tag' );
        add_submenu_page( 'tracking-label-plugin', 'Cadastro de Dados SIGEP', 'Cadastro de Dados do SIGEP', 'manage_options', 'tracking-label-plugin');
        add_submenu_page( 'tracking-label-plugin', 'Chancelas das etiquetas', 'Configurar chancelas das etiquetas', 'manage_options', 'chancelas-etiquetas', array($this, 'chancelasEtiquetas'));
    }
    
    public function dadosSigep() {
        if (isset($_POST) && count($_POST) > 0) {

            if ( ! ($this->has_valid_nonce() ) ) {
                ?><div>mwtbdltr</div><?php
                return;
            }

            if (
                isset($_FILES['etiqueta_correios_logomarca']) &&
                !empty($_FILES['etiqueta_correios_logomarca']['tmp_name']) &&
                is_uploaded_file($_FILES['etiqueta_correios_logomarca']['tmp_name'])
            ) {
                $logomarca = $_FILES['etiqueta_correios_logomarca'];

                $attachment_id = media_handle_upload('etiqueta_correios_logomarca', 0);

                if (is_wp_error($attachment_id)) {
                    echo '<div class="notice notice-error">Erro ao enviar a imagem</div>';
                } else {
                    update_option( 'etiqueta_correios_logomarca', $attachment_id );
                }
            }
    
            $payload = [];

            if ( null !== wp_unslash( $_POST['etiqueta_correios_login'] ) ) {
                $value = sanitize_text_field( $_POST['etiqueta_correios_login'] );
                $payload['usuario'] = $value;
                update_option( 'etiqueta_correios_login', $value );
            }     
    
            if ( null !== wp_unslash( $_POST['etiqueta_correios_senha'] ) ) {
                $value = sanitize_text_field( $_POST['etiqueta_correios_senha'] );
                $payload['senha'] = $value;
                update_option( 'etiqueta_correios_senha', $value );
            }     
    
            if ( null !== wp_unslash( $_POST['etiqueta_correios_codigo_administrativo'] ) ) {
                $value = sanitize_text_field( $_POST['etiqueta_correios_codigo_administrativo'] );
                $payload['codigo_administrativo'] = $value;
                update_option( 'etiqueta_correios_codigo_administrativo', $value );
            }     

            if ( null !== wp_unslash( $_POST['etiqueta_correios_numero_contrato'] ) ) {
                $value = sanitize_text_field( $_POST['etiqueta_correios_numero_contrato'] );
                $payload['idContrato'] = $value;
                update_option( 'etiqueta_correios_numero_contrato', $value );
            }

            if ( null !== wp_unslash( $_POST['etiqueta_correios_cartao_postagem'] ) ) {
                $value = sanitize_text_field( $_POST['etiqueta_correios_cartao_postagem'] );
                $payload['idCartaoPostagem'] = $value;
                update_option( 'etiqueta_correios_cartao_postagem', $value );
            }

            if ( null !== wp_unslash( $_POST['etiqueta_correios_cnpj'] ) ) {
                $value = sanitize_text_field( $_POST['etiqueta_correios_cnpj'] );
                update_option( 'etiqueta_correios_cnpj', $value );
            }

            if ( null !== wp_unslash( $_POST['etiqueta_correios_ano'] ) ) {
                $value = sanitize_text_field( $_POST['etiqueta_correios_ano'] );
                update_option( 'etiqueta_correios_ano', $value );
            }

            try {
                $client = new SoapClient(
                    $this->webserviceURL,
					array(
						'stream_context'=>stream_context_create(
							array('http'=>
								array(
									'protocol_version'=>'1.0',
									'header' => 'Connection: Close'
								)
							)
						)
					)
                );
                $servicos = $client->buscaServicos($payload)->return;
                $servicos_sigep = [];

                foreach ($servicos as $servico) {
                    $id = trim($servico->id);
                    $codigo = trim($servico->codigo);
                    $descricao = trim($servico->descricao);
                    $sigla = trim($servico->servicoSigep->categoriaServico);
                    $servicos_sigep[] = [
                        'id' => $id,
                        'codigo' => $codigo,
                        'descricao' => $descricao,
                        'sigla' => $sigla
                    ];
                }

                $cliente = $client->buscaCliente($payload);
                $diretoria = trim($cliente->return->contratos->codigoDiretoria);
                update_option( 'diretoria_sigep', $diretoria );
                update_option( 'chancelas_sigep', $servicos_sigep );
                echo '<div class="notice notice-success">Dados atualizados com sucesso.</div>';
            } catch (\Exception $ex) {
                update_option( 'chancelas_sigep', '' );
                echo '<div class="notice notice-error">Houve um erro ao conectar com o SIGEP. Confira seus dados e tente novamente.</div>';
            }
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
            <style>
                input::-webkit-outer-spin-button,
                input::-webkit-inner-spin-button {
                    -webkit-appearance: none;
                    margin: 0;
                }

                /* Firefox */
                input[type=number] {
                    -moz-appearance: textfield;
                }
            </style>
            <form method="post" enctype="multipart/form-data">
                <div id="universal-message-container">
                <h2>Dados de acesso ao SIGEP</h2>
                <div>Preencha as informações necessárias para conectar-se ao SIGEP e gerar as etiquetas.</div>
                
                <table class="form-table">
                    <tbody>
                        <tr valign="top">
                            <th scope="row" class="titledesc">
                                <label for="etiqueta_correios_login">Login </label>
                            </th>
                            <td class="forminp forminp-text">
                                <input name="etiqueta_correios_login" type="text" style="" value="<?php echo esc_attr( get_option( 'etiqueta_correios_login', '' ) ); ?>" class="" required placeholder="">
                                <p class="description">O login de acesso ao SIGEP.</p>
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row" class="titledesc">
                                <label for="etiqueta_correios_senha">Senha</label>
                            </th>
                            <td class="forminp forminp-text">
                                <input name="etiqueta_correios_senha" type="text" style="" value="<?php echo esc_attr(get_option( 'etiqueta_correios_senha', '' )); ?>" class="" required placeholder="">
                                <p class="description">A senha de acesso ao SIGEP.</p>
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row" class="titledesc">
                                <label for="etiqueta_correios_codigo_administrativo">Código Administrativo</label>
                            </th>
                            <td class="forminp forminp-text">
                                <input name="etiqueta_correios_codigo_administrativo" type="text" style="" value="<?php echo esc_attr(get_option( 'etiqueta_correios_codigo_administrativo', '' )); ?>" class="" required placeholder="">
                                <p class="description">O código administrativo do SIGEP.</p>
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row" class="titledesc">
                                <label for="etiqueta_correios_ano">Ano</label>
                            </th>
                            <td class="forminp forminp-text">
                                <input name="etiqueta_correios_ano" type="text" style="" value="<?php echo esc_attr(get_option( 'etiqueta_correios_ano', '' )); ?>" class="" required placeholder="">
                                <p class="description">Ano de assinatura do SIGEP.</p>
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row" class="titledesc">
                                <label for="etiqueta_correios_cnpj">CNPJ</label>
                            </th>
                            <td class="forminp forminp-text">
                                <input name="etiqueta_correios_cnpj" id="etiqueta_correios_cnpj" type="number" min="0" step="1" style="" value="<?php echo esc_attr( get_option( 'etiqueta_correios_cnpj', '' ) ); ?>" class="" required placeholder="">
                                <p class="description">CNPJ da empresa. Apenas números.</p>
                            </td>
                            <script>
                                document.getElementById("etiqueta_correios_cnpj").addEventListener('paste', (evt) => {
                                    var paste = (event.clipboardData || window.clipboardData).getData('text');
                                    paste = paste.replace(/\D/g, '');
                                    document.getElementById("etiqueta_correios_cnpj").value = paste;
                                    evt.preventDefault();
                                });

                                document.getElementById("etiqueta_correios_cnpj").addEventListener("keypress", function (evt) {
                                    if (evt.which < 48 || evt.which > 57) {
                                        evt.preventDefault();
                                    }
                                });
                            </script>
                        </tr>
                        <tr valign="top">
                            <th scope="row" class="titledesc">
                                <label for="etiqueta_correios_numero_contrato">Número do contrato</label>
                            </th>
                            <td class="forminp forminp-text">
                                <input name="etiqueta_correios_numero_contrato" type="text" style="" value="<?php echo esc_attr( get_option( 'etiqueta_correios_numero_contrato', '' ) ); ?>" class="" required placeholder="">
                                <p class="description">Número do contrato de acesso ao SIGEP. Apenas números.</p>
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row" class="titledesc">
                                <label for="etiqueta_correios_cartao_postagem">Número do cartão postagem</label>
                            </th>
                            <td class="forminp forminp-text">
                                <input name="etiqueta_correios_cartao_postagem" type="text" style="" value="<?php echo esc_attr( get_option( 'etiqueta_correios_cartao_postagem', '' ) ); ?>" class="" required placeholder="">
                                <p class="description">Número do cartão postagem de acesso ao SIGEP. Apenas números.</p>
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row" class="titledesc">
                                <label for="etiqueta_correios_logomarca">Logomarca</label>
                            </th>
                            <td class="forminp forminp-text">
                                <input name="etiqueta_correios_logomarca" type="file" value="" placeholder="" multiple="false">
                                <p class="description">A logomarca da empresa que será impressa na etiqueta (120 x 140).</p>
                                <?php                                
                                if (get_option( 'etiqueta_correios_logomarca', '' )) { ?>
                                    <img src="<?= wp_get_attachment_url( get_option( 'etiqueta_correios_logomarca', '' ), '' );  ?>" />
                                <?php } ?>
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row" class="titledesc">
                                <label for="etiqueta_numero_diretoria">Diretoria</label>
                            </th>
                            <td class="forminp forminp-text">
                                <p class="description"><?= esc_attr(get_option( 'diretoria_sigep', '' )); ?></p>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php
                    wp_nonce_field( 'tracking-label-config-save', 'tracking-label-config-custom-message' );
                    submit_button();
                ?>
            </div>
        </form>
        <?php
    }
    
    public function chancelasEtiquetas() {  
        if (isset($_POST) && count($_POST) > 0) {
            $chancelas = array_filter($_POST, function($key) {
                return strpos($key, 'chancela_') === 0;
            }, ARRAY_FILTER_USE_KEY);

            foreach ($chancelas as $key => $chancela) {
                if ( null !== wp_unslash( $chancela ) ) {
                    $value = sanitize_text_field( $chancela );
                    update_option( $key, $value );
                }
            }
        }

        $available_shipping_methods = [];
        $zone_ids = array_keys( array('') + WC_Shipping_Zones::get_zones() );

        foreach ( $zone_ids as $zone_id ) {
            $shipping_zone = new WC_Shipping_Zone($zone_id);

            $shipping_methods = $shipping_zone->get_shipping_methods( true, 'values' );
            if (count($shipping_methods) > 0) {
                foreach ( $shipping_methods as $instance_id => $shipping_method ) {
                    if (!in_array($shipping_method, $available_shipping_methods)) {
                        $available_shipping_methods[] = ['instance_id'=> $instance_id, 'method'=>$shipping_method];
                    }
                }
            }
        }
        
        $chancelas_sigep = get_option( 'chancelas_sigep', '' );
        if (count($available_shipping_methods) && is_array($chancelas_sigep)) {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
            <form method="post" enctype="multipart/form-data">
                <div id="universal-message-container">
                    <h2>Chancelas da etiqueta</h2>
                    <div>Selecione qual chancela deve ser utilizada em cada tipo de frete cadastrado.</div>
                    <table class="form-table">
                        <tbody>
                            <?php 
                                foreach ($available_shipping_methods as $sm) { ?>
                                    <tr valign="top">
                                        <th scope="row" class="titledesc">
                                            <label for="chancela_<?= $sm['method']->instance_id ?>"><?= $sm['method']->get_title() ?></label>
                                        </th>
                                        <td class="forminp forminp-text">
                                            <select name="chancela_<?= $sm['instance_id'] ?>">
                                                <option value="">(nenhuma)</option>
                                                <?php foreach ($chancelas_sigep as $chancela) { ?>
                                                    <option <?= strval(esc_attr( get_option( "chancela_{$sm['instance_id']}", '' ) )) === strval($chancela['id']).'|'.strval($chancela['codigo']).'|'.strval($chancela['sigla']) ? 'selected="selected"' : ''; ?> value="<?= strval($chancela['id']).'|'.strval($chancela['codigo']).'|'.strval($chancela['sigla']) ?>"><?= strval($chancela['codigo']).' - '.$chancela['descricao'] ?></option>
                                                <?php } ?>
                                            </select>
                                            <p class="description">A chancela para <?= $sm['method']->get_title() ?>.</p>
                                        </td>
                                    </tr>
                                <?php 
                                }  
                            ?>
                        </tbody>
                    </table>
                    <?php
                        wp_nonce_field( 'tracking-label-config-save', 'tracking-label-config-custom-message' );
                        submit_button();
                    ?>
                </div>
            </form>
        </div>
        <?php
        } else { ?>
        <div class="wrap"><p>Preencha seus dados para configurar a chancelas</p></div>
        <?php

        }
    }
    
    private function has_valid_nonce() {
        if ( ! isset( $_POST['tracking-label-config-custom-message'] ) ) {
            return false;
        }
     
        $field  = wp_unslash( $_POST['tracking-label-config-custom-message'] );
        $action = 'tracking-label-config-save';
     
        return wp_verify_nonce( $field, $action );
    }
}

add_filter('woocommerce_general_settings', 'general_settings_shop_phone');
function general_settings_shop_phone($settings) {
    $key = 0;

    foreach( $settings as $values ){
        $new_settings[$key] = $values;
        $key++;

        if($values['id'] == 'woocommerce_store_address'){
            $new_settings[$key] = array(
                'title'    => __('Número'),
                'desc'     => __('O número do endereço onde sua empresa esta localizada.'),
                'id'       => 'woocommerce_store_address_number',
                'default'  => '',
                'type'     => 'text',
                'desc_tip' => true,
            );
            $key++;
        }

        if($values['id'] == 'woocommerce_store_address_2') {
            $new_settings[$key] = array(
                'title'    => __('Bairro'),
                'desc'     => __('O nome do bairro onde sua empresa esta localizada.'),
                'id'       => 'woocommerce_store_neighborhood',
                'default'  => '',
                'type'     => 'text',
                'desc_tip' => true,
            );
            $key++;
        }
    }
    return $new_settings;
}

?>