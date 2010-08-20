# Wordpress plugin for Varnish

This plug-in uses a varnishadm socket connection to purge pages when content is altered.  
This includes editing of posts and addition of new comments.

Place the whole php-varnish package in your wp-content/plugins directory and enable it in the admin area. 
You can configure one of more Varnish front ends at *settings > Varnish admin*.

The sample VCL file wordpress.vcl is aggresive in maximizing cacheable pages. 
Only logged in admin users' cookies are allowed through.

## License

This plug-in, as with the rest of the php-varnish package, is released under the MIT license.


