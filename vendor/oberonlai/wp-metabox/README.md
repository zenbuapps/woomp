# WP Metabox v1.0

> Simple WordPress Class for metabox forks from [wp-metabox-constructor-class](https://github.com/MatthewKosloski/wp-metabox-constructor-class) 

## Requirements

* PHP >=7.2
* [Composer](https://getcomposer.org/)
* [WordPress](https://wordpress.org) >=5.4

## Installation

#### Install with composer

Run the following in your terminal to install with [Composer](https://getcomposer.org/).

```
$ composer require oberonlai/wp-metabox
```

WP Metabox [PSR-4](https://www.php-fig.org/psr/psr-4/) autoloading and can be used with the Composer's autoloader. Below is a basic example of getting started, though your setup may be different depending on how you are using Composer.

```php
require __DIR__ . '/vendor/autoload.php';

use ODS\Metabox;

$options = array( ... );

$books = new Metabox( $options );

```

See Composer's [basic usage](https://getcomposer.org/doc/01-basic-usage.md#autoloading) guide for details on working with Composer and autoloading.

## Basic Usage

Below is a basic example of setting up a simple custom filter with a post meta field.

```php
// Require the Composer autoloader.
require __DIR__ . '/vendor/autoload.php';

// Import PostTypes.
use ODS\Metabox;

```

## Usage

To create a metabox, first instantiate an instance of `Metabox`.  The class takes one argument, which is an associative array.  The keys to the array are similar to the arguments provided to the [add_meta_box](https://developer.wordpress.org/reference/functions/add_meta_box/) WordPress function; however, you don't provide `callback` or `callback_args`.

```php
$metabox = new Metabox(array(
	'id' => 'metabox_id',
	'title' => 'My awesome metabox',
	'screen' => 'post', // post type
	'context' => 'advanced', // Options normal, side, advanced.
	'priority' => 'default'
));
```

Please add the detection for the WooCommerce order metabox to check whether the HPOS is enabled or not.

```php
use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;

$screen = wc_get_container()->get( CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
		? wc_get_page_screen_id( 'shop-order' )
		: 'shop_order';

$metabox = new Metabox(
	array(
		'id'       => 'metabox_id',
		'title'    => 'My awesome metabox for WooCommerce order',
		'screen'   => $screen,
		'context'  => 'side',
		'priority' => 'high',
	)
);
```

## Available Fields

After instantiating the above metabox, add a few fields to it.  Below is a list of the available fields. 

### Text

A simple text input.  Nothing special.

```php
$metabox->addText(array(
	'id' => 'metabox_text_field',
	'label' => 'Text',
	'desc' => 'An example description paragraph that appears below the label.'
));
```

### Textarea

Textareas are used to store a body of text.  For a richer experience with HTML, see the [WYSIWYG](https://github.com/MatthewKosloski/wp-metabox-constructor-class#wysiwyg-editor) editor.

```php
$metabox->addTextArea(array(
	'id' => 'metabox_textarea_field',
	'label' => 'Textarea',
	'desc' => 'An example description paragraph that appears below the label.'
));
```

### Checkbox

Checkboxes are a great way to facilitate conditional logic.

```php
$metabox->addCheckbox(array(
	'id' => 'metabox_checkbox_field',
	'label' => 'Checkbox',
	'desc' => 'An example description paragraph that appears below the label.'
));
```

### Radio

Radio fields are a great way to choose from a selection of options.

```php
$metabox->addRadio(
	array(
		'id' => 'metabox_radio_field',
		'label' => 'Radio',
		'desc' => 'An example description paragraph that appears below the label.',
	),
	array(
		'key1' => 'Value One',
		'key2' => 'Value Two'
	)
);
```

### Select

Select fields are a great way to choose from a selection of options.

```php
$metabox->addSelect(
	array(
		'id' => 'metabox_select_field',
		'label' => 'Select',
		'desc' => 'An example description paragraph that appears below the label.',
	),
	array(
		'key1' => 'Value One',
		'key2' => 'Value Two'
	)
);
```

### Html

Add HTML markup to display information.

```php
$metabox->addHtml(
	array(
		'id' => 'metabox_html_field',
		'label' => 'html',
		'html' => '<h1>Hello World</h1>',
	),
);
```

### Image Upload

Use this to permit users to upload an image within the metabox.  Pro tip: use this with the [repeater](https://github.com/MatthewKosloski/wp-metabox-constructor-class#repeater) to dynamically manage the photos within a gallery or slideshow.

```php
$metabox->addImage(array(
	'id' => 'metabox_image_field',
	'label' => 'Image Upload',
	'desc' => 'An example description paragraph that appears below the label.'
));
```

### WYSIWYG Editor

You can use a WYSIWYG editor to facilitate the management of HTML content.

```php
$metabox->addEditor(array(
	'id' => 'metabox_editor_field',
	'label' => 'Editor',
	'desc' => 'An example description paragraph that appears below the label.'
));
```

### Repeater

All of the above fields can be added to a repeater to store an array of content with a dynamic length.  Here is an example of a repeater block with three fields: text, textarea, and image upload.

Notice:  `true` is a second argument to the repeater fields.  This is required.  Also, the variable, `$metabox_repeater_block_fields[]`, which stores the repeater block's fields, has a pair of brackets `[]` at the end of the variable name.  This is required. 

```php
$metabox_repeater_block_fields[] = $metabox->addText(array(
	'id' => 'metabox_repeater_text_field',
	'label' => 'Photo Title'
), true);

$metabox_repeater_block_fields[] = $metabox->addTextArea(array(
	'id' => 'metabox_repeater_textarea_field',
	'label' => 'Photo Description'
), true);

$metabox_repeater_block_fields[] = $metabox->addImage(array(
	'id' => 'metabox_repeater_image_field',
	'label' => 'Upload Photo'
), true);

$metabox->addRepeaterBlock(array(
	'id' => 'metabox_repeater_block',
	'label' => 'Photo Gallery',
	'fields' => $metabox_repeater_block_fields,
	'desc' => 'Photos in a photo gallery.',
	'single_label' => 'Photo'
));
```