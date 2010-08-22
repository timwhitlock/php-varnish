
backend origin {
    .host = "localhost";
    .port = "8080";
}


sub vcl_recv {
	# only using one backend
	set req.backend = origin;
	
	# set standard proxied ip header for getting original remote address
	set req.http.X-Forwarded-For = client.ip;
	
	# logged in users must always pass
	if( req.url ~ "^/wp-(login|admin)" || req.http.Cookie ~ "wordpress_logged_in_" ){
	    return (pass);
	}
       
        # don't cache search results
        if( req.url ~ "\?s=" ){
            return (pass);
        }

	# always pass through posted requests and those with basic auth
	if ( req.request == "POST" || req.http.Authorization ) {
 		return (pass);
	}
	
	# else ok to fetch a cached page
	unset req.http.Cookie;
	return (lookup);
}



sub vcl_fetch {

	# remove some headers we never want to see
	unset beresp.http.Server;
	unset beresp.http.X-Powered-By;
	
	# only allow cookies to be set if we're in admin area - i.e. commenters stay logged out
	if( beresp.http.Set-Cookie && req.url !~ "^/wp-(login|admin)" ){
		unset beresp.http.Set-Cookie;
	}
	
	# don't cache response to posted requests or those with basic auth
	if ( req.request == "POST" || req.http.Authorization ) {
 		return (pass);
	}
	
	# Trust Varnish if it says this is not cacheable
	if ( ! beresp.cacheable ) {
     	return (pass);
 	}

	# only cache status ok
	if ( beresp.status != 200 ) {
		return (pass);
	}

        # don't cache search results
        if( req.url ~ "\?s=" ){
            return (pass);
        }
	
	# else ok to cache the response
	set beresp.ttl = 24h;
	return (deliver);
}



sub vcl_deliver {
	# add debugging headers, so we can see what's cached
	if (obj.hits > 0) {
		set resp.http.X-Cache = "HIT";
 	}
 	else {
		set resp.http.X-Cache = "MISS";
	}
	# remove some headers added by varnish
	unset resp.http.Via;
	unset resp.http.X-Varnish;
}



sub vcl_hash {
    set req.hash += req.url;
    # altering hash so subdomains are ignored.
    # don't do this if you actually run different sites on different subdomains
    if ( req.http.host ) {
        set req.hash += regsub( req.http.host, "^([^\.]+\.)+([a-z]+)$", "\1\2" );
    } else {
        set req.hash += server.ip;
    }
    # ensure separate cache for mobile clients (WPTouch workaround)
    if( req.http.User-Agent ~ "(iPod|iPhone|incognito|webmate|dream|CUPCAKE|WebOS|blackberry9\d\d\d)" ){
    	set req.hash += "touch";
    }
    return (hash);
}   
