<?
/*
Plugin Name: WooCommerce 1c Import
Description: Import from 1c xml in wordpress WooCommerce
Version: 1.0
Author: Dmitriy Kupriynov
Author URI: http://dimonchik.com
License: GPLv2 or later
*/
error_reporting(E_ALL);
$newGroupsA=array();

require_once(ABSPATH . "wp-admin" . '/includes/image.php');

class mxXMLImport {
    var $xml_file_import;
    var $xml_file_offers;
    var $zip_file;
    var $groupsA;
    var $ifUpdate=true; // Нужно ли обновлять уже существующие товары и категории
    function __construct() {
        $this->xml_file_import=ABSPATH."1c/1cbitrix/import.xml";
        $this->xml_file_offers=ABSPATH."1c/1cbitrix/offers.xml";
        $this->zip_file=ABSPATH."1c/1cbitrix/1с.zip";
        $this->groupsA=array();
    }

    function getGroups($obj,$parent=0) {
        global $newGroupsA;
        if (isset($obj->{'Группы'})) {
            foreach($obj->{'Группы'}->{'Группа'} as $group) {
                $group_id=trim((string) $group->{'Ид'});
                if ($group_id=='b398432a-f3d3-11de-8867-002215104f65') {continue;}
                $group_name=str_replace(',  ',', ',str_replace(',',', ',trim((string) $group->{'Наименование'})));
                if (!$group_name) {continue;}

                $term=get_terms('product_cat',array(
                    'hide_empty'=>false,
                    'meta_query'=>array(
                        array(
                            'key'=>'mx_id',
                            'value'=>$group_id,
                        ),
                    ),
                ));
                if (count($term)) {
                    $newGroupsA[]=$term[0]->term_id;
                    $term=array('term_id'=>$term[0]->term_id);
                    if ($this->ifUpdate) {
                        wp_update_term($term['term_id'], 'product_cat', array(
                            'name' => $group_name,
                            'parent' => $parent,
                        ));
                    }
                } else {
                    $term=wp_insert_term($group_name,'product_cat',array('parent'=>$parent));
                    if (isset($term->error_data['term_exists'])) {continue;}
                    $index=0;
                    while (isset($term->errors)) {
                        $index++;
                        $term=wp_insert_term($group_name,'product_cat',array('parent'=>$parent,'slug'=>sanitize_title($group_name).'-'.$index));
                    }
                    update_term_meta($term['term_id'],'mx_id',$group_id);
                    $newGroupsA[]=$term['term_id'];
                }

                $this->groupsA[$group_id]=array(
                    'id'=>$term['term_id'],
                    'name'=>$group_name,
                    'parent'=>$parent,
                );
                if (isset($group->{'Группы'})) {$this->getGroups($group,(string) $term['term_id']);}
            }
        }
    }

    function ajax_import_xml() {
        @set_time_limit(0);
        ini_set('memory_limit', '800M');

        $files1 = scandir(ABSPATH."1c/1cbitrix");

        if(empty($files1[3])) exit;

        $this->zip_file=ABSPATH."1c/1cbitrix/".$files1[3];

        if(strlen($files1[3])>=8) {
            exit;
        }

        if (file_exists($this->zip_file)) {
            if($this->zipIsValid($this->zip_file)) {
                $zip=new ZipArchive();
                $zip->open($this->zip_file);
                $zip->extractTo(ABSPATH.'1c/1cbitrix');
                $zip->close();
                unlink($this->zip_file);
            } else {
                echo "An error occurred reading your ZIP file.";
                exit;
            }
        }

        if (!file_exists($this->xml_file_import) || !file_exists($this->xml_file_offers)) {return;}
        $offersA=array();
        $xml_offers=simplexml_load_file($this->xml_file_offers);
        $offers=$xml_offers->{'ПакетПредложений'}->{'Предложения'}->{'Предложение'};
        foreach($offers as $offer) {
            $offer_id=trim((string) $offer->{'Ид'});
            $offer_name=trim((string) $offer->{'Наименование'});
            $offer_description=(string) $offer->{'Описание'};
            if(!empty($offer_description)) {
                print_r($offer_description);
                exit;
            }
            if (isset($offer->{'Цены'}) && isset($offer->{'Цены'}->{'Цена'}[0]->{'ЦенаЗаЕдиницу'})) {
                $offer_price=(string) $offer->{'Цены'}->{'Цена'}[0]->{'ЦенаЗаЕдиницу'};
            } else {$offer_price='';}

            if (isset($offer->{'Количество'})) {
                if ((float) $offer->{'Количество'}>0) {$offer_stock='instock';} else {$offer_stock='outofstock';}
            } else {
                $offer_stock='outofstock';
            }
            $offersA[$offer_id]=array(
                'name'=>$offer_name,
                'price'=>$offer_price,
                'stock'=>$offer_stock,
                'groups'=>array(),
            );
        }
        $xml_import=simplexml_load_file($this->xml_file_import);
        $this->getGroups($xml_import->{'Классификатор'});
        global $newGroupsA;
        $terms=get_terms('product_cat',array(
            'hide_empty'=>false,
            'exclude'=>$newGroupsA
        ));

        /*foreach($terms as $term) {
            wp_delete_term($term->term_id,'product_cat');
        }*/

        $offers=$xml_import->{'Каталог'}->{'Товары'}->{'Товар'};
        foreach($offers as $offer) {
            $offer_id=(string) $offer->{'Ид'};
            if (!isset($offersA[$offer_id])) {continue;}
            if (isset($offer->{'Картинка'})) {$offersA[$offer_id]['image']=(string) $offer->{'Картинка'}[0];}
            $offersA[$offer_id]['description']=(string) $offer->{'Описание'}[0];
            $groups=$offer->{'Группы'}->{'Ид'};
            $groupsN=0;
            foreach($groups as $group) {
                if (!isset($this->groupsA[(string) $group])) {continue;}
                $groupsN++;
                $offersA[$offer_id]['groups'][]=$this->groupsA[(string) $group]['id'];
            }
            if ($groupsN==0) {unset($offersA[$offer_id]);}
        }

        $new_commodity_list=json_encode($offersA);
        file_put_contents(ABSPATH."1c/new_commodity_list.txt",$new_commodity_list);

        //foreach($offersA as $offer_id=>$offer) {
            //$this->update_or_insert_one_product($offer_id,$offer);
        //}
        //var_dump(count($offersA));
        unlink($this->xml_file_import);
        unlink($this->xml_file_offers);
        if (is_ajax()) {die();}
        return true;
    }

    function update_or_insert_one_product($offer_id,$offer) {
        $_post=get_posts(array(
            'post_type'=>'product',
            'meta_key'=>'mx_id',
            'meta_value'=>$offer_id,
        ));
        if (count($_post)) {
            $id=$_post[0]->ID;
            wp_update_post(array(
                'ID'=>$id,
                'post_title'=>$offer['name'],
                'post_content'=>$offer['description']
            ));
        
            $term = get_term( $offer['groups'][0], "product_cat" );
            wp_set_object_terms( $id, [$term->name], 'product_cat' );
        } else {
            $id=wp_insert_post(array(
                'post_type'=>'product',
                'post_title'=>$offer['name'],
                'post_content'=>$offer['description'],
                'post_status'   => 'publish'
            ));

            $term = get_term( $offer['groups'][0], "product_cat" );
            wp_set_object_terms( $id, [$term->name], 'product_cat' );

            update_post_meta($id,'mx_id',$offer_id);
            update_post_meta($id,'_visibility','visible');
        }

        echo $id.",";

        if (!count($_post) || $this->ifUpdate) {
            if(!$offer['price'] || $offer['stock']=="outofstock") {
                wp_delete_post( $id, true );
                return false;
            }

            update_post_meta($id, '_price', $offer['price']);
            update_post_meta($id, '_sale_price', $offer['price']);
            update_post_meta($id, '_regular_price', $offer['price']);
            update_post_meta($id, '_stock_status', $offer['stock']);
            //update_post_meta($id,'_sku',$offer_id);
        }

        if (!count($_post) || $this->ifUpdate) {
            if (isset($offer['image'])) {
                $fnImageOld=dirname($this->xml_file_offers).'/'.$offer['image'];
                if (file_exists($fnImageOld)) {
                    $fnImage=ABSPATH . 'wp-content/uploads/products/'.basename($offer['image']);
                    rename($fnImageOld,$fnImage);
                    $wp_filetype = wp_check_filetype(basename($fnImage), null);
                    $wp_upload_dir = wp_upload_dir();

                    if (count($_post)) {
                        $thumb_id = get_post_thumbnail_id($_post[0]->ID);
                        $thumb_post = get_post($thumb_id);
                    }

                    if (!count($_post) || (isset($thumb_post->guid) && $thumb_post->guid!=$wp_upload_dir['url'] . '/' . basename($fnImage)) || (isset($thumb_id) && $thumb_id=='')) {
                        if (count($_post) && $thumb_id!='') {wp_delete_attachment($thumb_id,true);}
                        $attachment = array(
                            'guid' => $wp_upload_dir['url'] . '/' . basename($fnImage),
                            'post_mime_type' => $wp_filetype['type'],
                            'post_title' => preg_replace('/\.[^.]+$/', '', basename($fnImage)),
                            'post_content' => '',
                            'post_status' => 'inherit'
                        );
                        $attach_id = wp_insert_attachment($attachment, $fnImage, $id);
                        update_post_meta($id,'_thumbnail_id',$attach_id);
                        $attach_data = wp_generate_attachment_metadata($attach_id, $fnImage);
                        wp_update_attachment_metadata($attach_id, $attach_data);
                    } elseif (count($_post)) {
                        $attach_data = wp_generate_attachment_metadata($thumb_id, $fnImage);
                        wp_update_attachment_metadata($thumb_id, $attach_data);
                    }
                }
            }
        }
    }

    function zipIsValid($path) {
      $zip = zip_open($path);
      if (is_resource($zip)) {
        // it's ok
        zip_close($zip); // always close handle if you were just checking
        return true;
      } else {
        return false;
      }
    }

}

$mxXMLImport=new mxXMLImport();

add_action('wp_ajax_import_xml', array($mxXMLImport,'ajax_import_xml'));
add_action('wp_ajax_nopriv_import_xml', array($mxXMLImport,'ajax_import_xml'));

add_action('init','mximport_init');
function mximport_init()
{
    if (isset($_REQUEST['action']) && $_REQUEST['action'] == 'doImport') {
        $mxXMLImport=new mxXMLImport();
        if (isset($_FILES['xml']['size']) && $_FILES['xml']['size'] != 0) {
            move_uploaded_file($_FILES['xml']['tmp_name'], $mxXMLImport->zip_file);
        }

        $result=$mxXMLImport->ajax_import_xml();
        //var_dump($_REQUEST['action'],$_REQUEST['return']);exit;
        if (is_ajax() && $result===true) {
            $_SESSION['import_ok']=1;
            header('location:'.$_SERVER['HTTP_REFERER']);
        } elseif (isset($_REQUEST['return']) && $result==true) {
            $_SESSION['import_ok']=1;
            header('location:'.$_REQUEST['return']);
        } else {
            echo 'Импорт успешно выполнен';
        }
        exit;
    } else if (isset($_REQUEST['action']) && $_REQUEST['action'] == 'import_one_product') {
        $mxXMLImport=new mxXMLImport();
        foreach ($_POST["list_product"] as $v) {
            $mxXMLImport->update_or_insert_one_product($v["index"],$v["data"]);
        }
        exit;
    } else if(isset($_REQUEST['action']) && $_REQUEST["action"]=="delete_all_empty_category_post") {
        $args = array( 'posts_per_page' => -1, 'post_type'=> "product");
        $myposts = get_posts( $args );
        foreach ($myposts as $value) {
            $terms = wp_get_post_terms($value->ID, "product_cat");
            if(empty($terms)) {
                wp_delete_post($value->ID,true);
            }
        }
        exit;
    } else if(isset($_REQUEST['action']) && $_REQUEST["action"]=="get_outofstock_product") {

        $args = array( 
            'post_type'   => array( 'product' ),
            'post_status' => get_post_status(),
            'numberposts' => -1,
            );
        $products = get_posts( $args );

        $all_product=[];
        foreach ($products as $key => $value) {
            $all_product[] = $value->ID;
        }

        //echo count($all_product);
        echo json_encode($all_product);
        exit;
    } else if(isset($_REQUEST['action']) && $_REQUEST['action']=="remove_product_by_id" && !empty($_REQUEST['post_id'])) {
    	wp_delete_post($_REQUEST['post_id'],true);
    }
}