# CSV Product Importer Plugin

This WordPress plugin allows you to easily create WooCommerce simple products by reading data from a CSV file stored in the root directory under the `csv` folder. The plugin adds a new menu page in the backend for importing products from CSV files.

## Features

### 1. **CSV File Handling**
- The plugin reads product information from a CSV file located in the root directory under the `csv` folder.
- The CSV should contain product details such as title, description, price, SKU, stock status, and image URLs.

### 2. **Product Creation**
- The plugin automatically creates simple WooCommerce products based on the data from the CSV file.
- Each product is created with the necessary attributes like title, price, SKU, etc., as per the data in the CSV file.

### 3. **Backend Menu Page**
- A new menu page titled **Create Products from CSV** is added to the WordPress admin dashboard.
- This page includes a **Start Import** button to initiate the import process.

### 4. **Batch Process and AJAX Import**
- The import process happens in batches to avoid timeouts and performance issues.
- The plugin counts the total number of products to import, then uploads the products using an AJAX-based batch process. This ensures that large imports run smoothly without page refreshes.

### 5. **Image Download and Upload**
- The plugin automatically downloads images from external URLs (provided in the CSV file) and uploads them to the WordPress media library.
- This feature simplifies the product creation process by fetching product images from third-party sources and associating them with the newly created products.

## CSV File for Product Import

The CSV file used to import products is located in the `csv` folder. This file contains all the necessary data for creating products in the WooCommerce store, including product titles, SKUs, descriptions, and image URLs.

You can view the file here: [csv/VG.csv](csv/VG.csv)

## How It Works

1. **Upload CSV File**: Place the CSV file in the `csv` folder in the root directory of your WordPress installation.
2. **Access the Plugin**: Navigate to the **Create Products from CSV** page in the WordPress admin dashboard.
3. **Start Import**: Click the **Start Import** button to begin the process.
4. **Batch Import**: The plugin counts the products and processes them in batches using AJAX.
5. **Download and Upload Images**: If the CSV contains image URLs, the plugin will download them and upload them to the WordPress media library.

## Requirements
- WordPress 5.0 or higher
- WooCommerce 4.0 or higher
- PHP 7.2 or higher

## Installation

1. Download and extract the plugin files.
2. Upload the plugin folder to your WordPress `wp-content/plugins/` directory.
3. Activate the plugin from the **Plugins** menu in WordPress.