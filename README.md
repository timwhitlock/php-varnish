# php-varnish

PHP tools for working with Varnish reverse proxy cache.  
Authors: [Tim Whitlock](http://twitter.com/timwhitlock)  
See http://varnish-cache.org/ for information about Varnish  
	
## Admin socket

Currently this project comprises an admin socket class, which PHP applications can use to interface with the varnishadm program.  
Common tasks would include checking the health of caches anmd purging when site content needs refreshing.

## Todo

* varnishadm authentication
* Add all short cut methods to commands listed below
* Sanitise admin command parameters, such as regexp
* HTTP tools
