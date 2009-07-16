Unicorn VuFind Driver - Basic Setup Instructions

This document will provide a really basic explanation of how to get started.
Hopefully it will be expanded in the future.

All of this code assumes that your Unicorn server and your VuFind server are
separate machines.  If they are not, you will probably need to make some
adjustments.

1.  Copy driver/vufind_side/Unicorn.php to your Drivers directory on your
    VuFind server.  In a standard install, this will be:

    /usr/local/vufind/web/Drivers/

    Overwrite the included Unicorn.php, you won't be using it.

2.  Copy driver/sirsi_side/driver.pl to your Unicorn server.  It needs to be
    placed in a directory accessible by apache-su (Apache running as sirsi
    user).  As this is a Perl script, the directory must also have ExecCGI
    enabled in your Apache config.

    In our case, we used ~/public_html/webapi/vufind/
    
    This file expects to get a catkey as the ID (not the flexkey), so make
    sure your VuFind is indexed appropriately (see indexing_tips.txt for
    details).
