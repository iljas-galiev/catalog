<?php

class YmlModule extends CWebModule {
	public function init () {
		$this->setImport(array(
			'yml.models.*',
			'yml.components.*',
		));
	}

	public function beforeControllerAction ($controller, $action) {
		if(parent::beforeControllerAction($controller, $action)) {
			return true;
		}else
			return false;
	}

	public static function import ($shop_id) {
		$start = microtime(true);
		session_write_close();
		set_time_limit(36000 * 1000 * 1000);
		//fastcgi_finish_request();

		$yml = Yml::model()->findByPk($shop_id);

		if(!$yml) return false;

		if($yml->importing) return false;

		if(!$xml = simplexml_load_file($yml->url)) return false;

		$yml->importing = 1;
		$yml->save();

		if(!$yml->iterations) {
			self::importCategories($yml, $xml);
		}else {
			self::compareCategories($yml, $xml);
		}
		self::compareProducts($yml, $xml);
		$yml->importing = 0;
		$yml->last_import = time();
		$yml->iterations++;
		$yml->save();

		$time = microtime(true) - $start;
		printf('Скрипт выполнялся %.4F сек.', $time);

	}

	private static function importCategories ($yml, $xml) {
		$ids = [];
		foreach($xml->shop->offers->offer as $offer) $ids[] = (int) $offer->categoryId;
		foreach($xml->shop->categories->category as $category) {
			Yii::app()->db->createCommand()->insert(YmlCategory::model()->tableName(), [
				'shop_id' => $yml->shop_id,
				'yml_cat_id' => $category['id'],
				'site_cat_id' => NULL,
				'site_parent_cat_id' => NULL,
				'yml_cat_name' => $category,
				'products' => (in_array($ids, (int) $category['id'])) ? 1 : 0,
				'yml_cat_parent_id' => (isset($category['parentId'])) ? $category['parentId'] : NULL
			]);
		}
	}

	private static function compareCategories ($yml, $xml) {
		$ids = [];
		foreach($xml->shop->offers->offer as $offer) $ids[] = (int) $offer->categoryId;

		foreach($xml->shop->categories->category as $category) {
			$yml_cat = YmlCategory::model()->findByAttributes(['yml_cat_id' => (int) $category['id']]);
			if($yml_cat !== NULL) {
				if(trim($yml_cat->yml_cat_name) != trim((string) $category)) {
					YmlCategory::model()->deleteByPk($yml_cat->id);
					Yii::app()->db->createCommand()->insert(YmlCategory::model()->tableName(), [
						'shop_id' => $yml->shop_id,
						'yml_cat_id' => $category['id'],
						'site_cat_id' => NULL,
						'site_parent_cat_id' => NULL,
						'yml_cat_name' => $category,
						'yml_cat_parent_id' => (isset($category['parentId'])) ? $category['parentId'] : NULL,
						'products' => (in_array((int) $category['id'], $ids)) ? 1 : 0
					]);
					continue;
				}
				if(isset($category['parentId']) && $yml_cat->yml_cat_parent_id !== NULL) {
					if($yml_cat->yml_cat_parent_id != (int) $category['parentId']) {
						YmlCategory::model()->deleteByPk($yml_cat->id);
						Yii::app()->db->createCommand()->insert(YmlCategory::model()->tableName(), [
							'shop_id' => $yml->shop_id,
							'yml_cat_id' => $category['id'],
							'site_cat_id' => NULL,
							'site_parent_cat_id' => NULL,
							'yml_cat_name' => $category,
							'products' => (in_array((int) $category['id'], $ids)) ? 1 : 0,
							'yml_cat_parent_id' => (isset($category['parentId'])) ? $category['parentId'] : NULL
						]);
						continue;
					}
				}
			}else {
				Yii::app()->db->createCommand()->insert(YmlCategory::model()->tableName(), [
					'shop_id' => $yml->shop_id,
					'yml_cat_id' => $category['id'],
					'site_cat_id' => NULL,
					'site_parent_cat_id' => NULL,
					'yml_cat_name' => $category,
					'products' => (in_array((int) $category['id'], $ids)) ? 1 : 0,
					'yml_cat_parent_id' => (isset($category['parentId'])) ? $category['parentId'] : NULL
				]);
			}
		}
		$category_ids = Yii::app()->db->createCommand()->select(['id', 'yml_cat_id'])->from(YmlCategory::model()->tableName())->queryAll();
		$cat_map = [];
		foreach($xml->shop->categories->category as $category) {
			$cat_map[(int) $category['id']] = '';
		}
		foreach($category_ids as $key) {
			$id = $key['id'];
			$ymlid = $key['yml_cat_id'];
			if(!isset($cat_map[$ymlid])) {
				Yii::app()->db->createCommand()->delete(YmlCategory::model()->tableName(), 'id=:id', [':id' => $id]);
			}
		}
	}


	private static function compareProducts ($yml, $xml) {

		self::productsInit($xml);
		$map = &self::$_products;
		YmlError::removeErrors($yml->shop_id);
		unset($xml);
		$currs = Currency::getCurrs();
		$models = [];
		/*$products = Product::model()->findAll('shop_id=' . $yml->shop_id);
		foreach($products as $product) {
			$models[$product->yml_product_id] = $product;
		}*/

		foreach($map as $id => $arr) {
			if($yml->iterations)
				//$product = $models[$id];
				$product = Product::model()->findByAttributes(['yml_product_id' => $id, 'shop_id' => $yml->shop_id]);
			else $product = NULL;
			if($product) {
				if(self::compareObjs($product, $arr, $yml->shop_id) === true) {
					if($arr['curr'] == 'RUB') {
						$curr = 1;
						$product->price = (int) $arr['price'];
					}else {
						if(isset($currs[$arr['curr']]))
							$curr = $currs[$arr['curr']];
						else $curr = NULL;
						$product->price = (int) $arr['price'] * (int) $curr;
					}
					if($product->category_id === NULL) YmlError::addYmlError($yml->shop_id, $product->id, YmlError::ERR_NO_CAT);
					if($product->brand_id === NULL) YmlError::addYmlError($yml->shop_id, $product->id, YmlError::ERR_NO_BRAND);
					if(!$curr) YmlError::addYmlError($yml->shop_id, $product->id, YmlError::ERR_NO_CURR);
					if(!$product->main_image) YmlError::addYmlError($yml->shop_id, $product->id, YmlError::ERR_NO_IMAGE);
					if(!$product->name) YmlError::addYmlError($yml->shop_id, $product->id, YmlError::ERR_NO_NAME);
					if(!$product->description) YmlError::addYmlError($yml->shop_id, $product->id, YmlError::ERR_NO_DESC);
					if(!$product->link) YmlError::addYmlError($yml->shop_id, $product->id, YmlError::ERR_NO_LINK);
					ProductImage::saveImages($arr['images'], $product->id, false);


				}else {

					Product::$old_image = $product->main_image_url;

					$product->name = self::compareAttr($product->name, $arr['name']);
					$product->description = self::compareAttr($product->description, $arr['description']);
					$product->link = self::compareAttr($product->link, trim($arr['url']));
					if($arr['curr'] == 'RUB') {
						$curr = 1;
						$product->price = (int) $arr['price'];
					}else {
						if(isset($currs[$arr['curr']]))
							$curr = $currs[$arr['curr']];
						else $curr = NULL;
						$product->price = (int) $arr['price'] * (int) $curr;
					}
					$product->main_image_url = self::compareAttr($product->main_image_url, $arr['main_image']);
					$product->brand_id = self::compareAttr($product->brand_id, Brand::getByKey($arr['vendor']));
					$product->category_id = self::compareAttr($product->category_id, YmlCategory::getCategory($yml->shop_id, $arr['category_id'], $product->name));

					if($product->category_id !== NULL && $curr !== NULL && Image::imageExists($product->main_image_url) && $product->name && $product->description && $product->link) {
						$product->status = Product::STATUS_PUBLISHED;
					}

					$product->save();

					ProductImage::saveImages($arr['images'], $product->id, false);
					if($product->category_id === NULL) YmlError::addYmlError($yml->shop_id, $product->id, YmlError::ERR_NO_CAT);
					if($product->brand_id === NULL) YmlError::addYmlError($yml->shop_id, $product->id, YmlError::ERR_NO_BRAND);
					if(!$curr) YmlError::addYmlError($yml->shop_id, $product->id, YmlError::ERR_NO_CURR);
					if(!$product->main_image) YmlError::addYmlError($yml->shop_id, $product->id, YmlError::ERR_NO_IMAGE);
					if(!$product->name) YmlError::addYmlError($yml->shop_id, $product->id, YmlError::ERR_NO_NAME);
					if(!$product->description) YmlError::addYmlError($yml->shop_id, $product->id, YmlError::ERR_NO_DESC);
					if(!$product->link) YmlError::addYmlError($yml->shop_id, $product->id, YmlError::ERR_NO_LINK);
				}
			}else {
				$product = new Product();
				$product->name = $arr['name'];
				$product->description = $arr['description'];
				$product->link = $arr['url'];
				$product->main_image_url = $arr['main_image'];
				if($arr['curr'] == 'RUB') {
					$curr = 1;
					$product->price = (int) $arr['price'];
				}else {
					if(isset($currs[$arr['curr']]))
						$curr = $currs[$arr['curr']];
					else $curr = 0;
					$product->price = (int) $arr['price'] * (int) $curr;
				}
				$product->available = $arr['available'];
				$product->category_id = YmlCategory::getCategory($yml->shop_id, (int) $arr['category_id'], $product->name);
				$product->click = 0;
				$product->shop_id = $yml->shop_id;
				$product->brand_id = Brand::getByKey($arr['vendor']);
				$product->yml_product_id = $id;
				$product->status = Product::STATUS_NOT_PUBLISHED;


				$product->save(false);

				ProductImage::saveImages($arr['images'], $product->id, true);
				if($product->category_id === NULL) YmlError::addYmlError($yml->shop_id, $product->id, YmlError::ERR_NO_CAT);
				if($product->brand_id === NULL) YmlError::addYmlError($yml->shop_id, $product->id, YmlError::ERR_NO_BRAND);
				if(!$curr) YmlError::addYmlError($yml->shop_id, $product->id, YmlError::ERR_NO_CURR);
				if(!$product->main_image) YmlError::addYmlError($yml->shop_id, $product->id, YmlError::ERR_NO_IMAGE);
				if(!$product->name) YmlError::addYmlError($yml->shop_id, $product->id, YmlError::ERR_NO_NAME);
				if(!$product->description) YmlError::addYmlError($yml->shop_id, $product->id, YmlError::ERR_NO_DESC);
				if(!$product->link) YmlError::addYmlError($yml->shop_id, $product->id, YmlError::ERR_NO_LINK);
			}
			unset($product);
		}
		unset($map);
		$product_ids = Yii::app()->db->createCommand()->select(['id', 'yml_product_id'])->from(Product::model()->tableName())->where(['shop_id=:shop_id'], [':shop_id' => $yml->shop_id])->queryAll();
		foreach($product_ids as $key) {
			$id = $key['id'];
			$ymlid = $key['yml_product_id'];
			if(!isset($map[$ymlid])) {
				Yii::app()->db->createCommand()->delete(Product::model()->tableName(), 'id=:id', [':id' => $id]);
			}
		}
		echo 'ok';
		echo '<br>' . memory_get_usage() . '<br>';
	}

	private static $_products = [];

	private static function productsInit ($xml) {
		if(empty(self::$_products)) {
			foreach($xml->shop->offers->offer as $offer) {
				$id = (int) $offer['id'];
				self::$_products[$id]['name'] = (string) $offer->name;
				self::$_products[$id]['description'] = trim((string) $offer->description);
				self::$_products[$id]['url'] = trim((string) $offer->url);
				self::$_products[$id]['price'] = (int) $offer->price;
				self::$_products[$id]['main_image'] = trim((string) $offer->picture[0]);
				self::$_products[$id]['curr'] = (string) $offer->currencyId;
				self::$_products[$id]['vendor'] = (string) $offer->vendor;
				self::$_products[$id]['available'] = ($offer['available'] == 'true') ? 1 : 0;
				self::$_products[$id]['category_id'] = (int) $offer->categoryId;
				self::$_products[$id]['images'] = (array) $offer->picture;
				if(!empty(self::$_products[$id]['images'])) unset(self::$_products[$id]['images'][0]);
			}
		}
		return true;
	}

	private static function compareAttr ($productAttr, $offerAttr) {

		if($productAttr != $offerAttr) {
			return $offerAttr;
		}else return $productAttr;
	}


	private static function compareObjs ($product, $arr, $shop_id) {
		if($product->name != $arr['name']) return false;
		elseif($product->name != $arr['name']) return false;
		elseif($product->description != $arr['description']) return false;
		elseif($product->link != $arr['url']) return false;
		//elseif($product->price != $arr['price']) return false;
		elseif($product->main_image_url != $arr['main_image']) return false;
		elseif($product->category_id !== YmlCategory::getCategory($shop_id, (int) $arr['category_id'], $product->name)) return false;
		elseif($product->available != $arr['available']) return false;
		else return true;
	}
}