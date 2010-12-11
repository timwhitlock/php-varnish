# php-varnish

PHP tools for working with Varnish reverse proxy cache.

* Authors: [Tim Whitlock](http://twitter.com/timwhitlock)
* See [varnish-cache.org](http://varnish-cache.org/) for information about Varnish
	
## Admin socket

This package includes an admin socket class, which PHP applications can use to interface with the **varnishadm** program.  
Common tasks would include checking the health of caches and purging when site content needs refreshing.

## Wordpress plug-in

This package includes a Wordpress plug-in. See wordpress-plugin/README.md


## Todo

* Add short cut methods for all admin commands
* Sanitise admin command parameters, such as regexp
* HTTP tools
* Drupal module

## License

The whole php-varnish package, is released under the MIT license, see LICENSE.
