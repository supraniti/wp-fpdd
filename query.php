<?php
  $action = $_GET['action'];
  if (!$action){
   exit;
  }
  else if ($action == 'post'){
   $post_id =  $_GET["id"];
   if (!$post_id){
     exit;
   }
   $post_type = $_GET["type"];
   if (!$post_type){
     $post_type = 'post';
   }
   $posts_data = json_decode(file_get_contents( __DIR__ . '\/cache\/' . $post_type . '.json'),true);
   $result = array_merge (array("post_id"=>$post_id),$posts_data[$post_id]);
   header("cache-control: public, max-age=31536000");
   echo json_encode($result,JSON_UNESCAPED_UNICODE);
   exit;
  }
  else if ($action == 'posts'){
   $query = array(
   	'per_page'=> 24,
   	'page'=> 1,
   	'offset'=> 0,
   	'orderby'=> 'date',
   	'order'=> 'desc',
   );
   $filter_query = array();
   foreach ($_GET as $key=>$value){
   	if (array_key_exists($key,$query)){
   		$query[$key] = $value;
   	}
    else if(!explode('variant_',$key)[1] && $key!='action' && $key!='version'){
   		$filter_query[$key] = $value;
   	}
   }
   function filter_test($key,$value,$product_id){
   	global $numeric_data;
   	global $text_data;
   	global $taxonomy_data;
   	switch ($key) {
   		case 'min_price':
   			return intval($numeric_data[$product_id]['price']) >= intval($value);
   			break;
   		case 'max_price':
   			return intval($numeric_data[$product_id]['price']) <= intval($value);
   			break;
   		case 'rating':
   			return intval($numeric_data[$product_id]['rating'] >= $value);
   			break;
   		case 'instock':
   			return $numeric_data[$product_id]['stock'] > 0 ? $value != 'false' : $value == 'false';
   			break;
   		case 'search':
   			return ( (stripos($text_data[$product_id]['desc'] , $value) !== false) || (stripos($text_data[$product_id]['name'] , $value) !== false) );
   			break;
   		default:
   		return short_circuit_array_some($taxonomy_data[$product_id][$key],explode(',',$value));
   	}
   }
   function short_circuit_array_some($arr1,$arr2){
    foreach($arr1 as $item1){
      foreach($arr2 as $item2){
          if ($item1 == $item2 || intval($item1) == intval($item2)){
            return true;
            break;
          }
      }
    }
    return false;
   }
   $text_data = json_decode(file_get_contents( __DIR__ . "/static/PRODUCTS_TEXT.json"),true);
   $numeric_data = json_decode(file_get_contents( __DIR__ . "/static/PRODUCTS_NUMERIC.json"),true);
   $taxonomy_data = json_decode(file_get_contents( __DIR__ . "/static/PRODUCTS_TAXONOMIES.json"),true);
   $variation_price_range = json_decode(file_get_contents( __DIR__ . "/static/VARIATIONS_PRICE_RANGE.json"),true);
   $result = array(
  	 'products' => array(),
     'prev' => null,
     'next' => null,
   	 'size'=>0,
   );
   //Load sort indices
   $sorted = json_decode(file_get_contents( __DIR__ . '/static/SORTS.json'),true)[$query['orderby']];
   if ($query['order'] !== 'asc'){
   	$sorted = array_reverse ($sorted);
   }
   //Filter
   $filtered = [];
   foreach ($sorted as $product_id){
   	$validated = true;
   	foreach ($filter_query as $key=>$value){
   		if (!filter_test($key,$value,$product_id)){
   			$validated = false;
   			break;
   		}
   	}
   	if ($validated){
   		$filtered[]=$product_id;
   		$result['size']+=1;
		foreach($taxonomy_data[$product_id] as $key=>$value){
			foreach($value as $term){
				$result['taxonomies'][$key][$term]+=1;
			}
		}
		$result['min_price'] = $result['min_price'] ? min($result['min_price'],$numeric_data[$product_id]['price']) : $numeric_data[$product_id]['price'];
		$result['max_price'] = $result['max_price'] ? max($result['max_price'],$numeric_data[$product_id]['price']) : $numeric_data[$product_id]['price'];
   	}
   }
   //Paginate
   $offset = $query['offset'] + ($query['page']-1) * $query['per_page'];
   $length = $query['per_page'];
   $page = array_slice($filtered,$offset,$length);

   //Deliver
   foreach ($page as $product_id){
   	$pid = array(
   		'product_id' => $product_id,
		'variation_price_range' => $variation_price_range[$product_id] ? $variation_price_range[$product_id] : [],
		'isVariable' => $variation_price_range[$product_id] ? true : false,
   	);
   	$result['products'][] = array_merge($pid,$text_data[$product_id],$numeric_data[$product_id],$taxonomy_data[$product_id]);
   }
   //prev
   $prev_id = $filtered[$offset - 1];
   if ($prev_id){
     $pid = array(
       'product_id' => $prev_id,
       'variation_price_range' => $variation_price_range[$prev_id] ? $variation_price_range[$prev_id] : []
     );
     $result['prev'] = array_merge($pid,$text_data[$prev_id],$numeric_data[$prev_id],$taxonomy_data[$prev_id]);
   }
   else{
     $result['prev'] = false;
   }
   //next
   $next_id = $filtered[$offset + $length];
   if ($next_id){
     $pid = array(
       'product_id' => $next_id,
       'variation_price_range' => $variation_price_range[$next_id] ? $variation_price_range[$next_id] : []
     );
     $result['next'] = array_merge($pid,$text_data[$next_id],$numeric_data[$next_id],$taxonomy_data[$next_id]);
   }
   else{
     $result['next'] = false;
   }

   //Send
   header("cache-control: public, max-age=31536000");
   echo json_encode($result,JSON_UNESCAPED_UNICODE);
   exit;
  }
  else if ($action == 'comments'){
   $product_id =  $_GET["id"];
   if ($product_id){
     $reviews = json_decode(file_get_contents( __DIR__ . "/static/REVIEWS.json"),true);
     header("cache-control: public, max-age=0");
     echo json_encode($reviews[$product_id],JSON_UNESCAPED_UNICODE);
     exit;
   }
   else{
     exit;
   }
  }
