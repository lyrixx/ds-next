vcl 4.1;

# The VCL of the project: docker-starter never changes this file (see README.md).
#
# Varnish comes last among the reverse proxies of the extensions, so it forwards to
# nginx, in the frontend container.
backend default {
    .host = "frontend";
    .port = "8080";
}

# The defaults of Varnish (builtin.vcl) apply after these subroutines: no cache for the
# requests with a cookie or an Authorization header, nor for the responses with a
# Set-Cookie header, and the TTL from the Cache-Control header of the response.
#
# To invalidate the cache from the application (e.g. with FOSHttpCache), see
# https://foshttpcache.readthedocs.io/en/latest/varnish-configuration.html

sub vcl_backend_response {
    # Only what the application caches on purpose: Varnish caches the other responses
    # for 2 minutes by default, which would hide the changes of the code in dev
    if (!beresp.http.Cache-Control && !beresp.http.Expires) {
        set beresp.uncacheable = true;
        return (deliver);
    }
}

sub vcl_deliver {
    # To see what the cache does
    if (obj.hits > 0) {
        set resp.http.X-Cache = "HIT";
    } else {
        set resp.http.X-Cache = "MISS";
    }
}
