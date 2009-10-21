Unicorn VuFind Driver - Basic Setup Instructions

This document will provide a really basic explanation of how to get started.
Hopefully it will be expanded in the future.

All of this code assumes that your Unicorn server and your VuFind server are
separate machines.  If they are not, you will probably need to make some
adjustments.

Start with the SIRSI side:

1.  Copy driver/sirsi_side/driver.pl to your Unicorn server.  It needs to be
    placed in a directory accessible by apache-su (Apache running as sirsi
    user).  As this is a Perl script, the directory must also have ExecCGI
    enabled in your Apache config.

    In our case, we used ~/public_html/webapi/vufind/
    
    This file expects to get a catkey as the ID (not the flexkey), so make
    sure your VuFind is indexed appropriately (see indexing_tips.txt for
    details).

2.  Edit the driver.pl file.  Near the top, you will find a variable called
    $unicorn_base_path.  Edit this variable to match the actual path to your
    Unicorn installation.

3.  Make sure the driver.pl file is set to be executable
    (run 'chmod 755 driver.pl', or something similar).

4.  Test the driver first from the command line.  When in the directory where
    you placed the driver, run:
    ./driver.pl "search=single&id=123456"
    where '123456' is an actual catkey in your Sirsi system.  You should get
    something like:
    Content-Type: text/html; charset=ISO-8859-1

    123456|BS480 .T48|4TH-FLOOR|0|0|LIBRARY|0|
    
    If you do not, stop here and contact the list for help.

5.  Next, test the driver from the web.  Browse to whereever you placed the
    driver and run the same query, that is:
    http://www.yourserver.edu:3000/~sirsi/webapi/vufind/driver.pl?search=single&id=123456

    You should get the same output as you did from the command line, except
    you will no longer see the "Content-type" line.

That's it!  You have successfully set up the Sirsi side of this connector.
Please contact the list if you have any issues at all.


Now, set up the VuFind side.  This is much simpler.

1.  Copy driver/vufind_side/Unicorn.php to your Drivers directory on your
    VuFind server.  In a standard install, this will be:

    /usr/local/vufind/web/Drivers/

    Overwrite the included Unicorn.php, you won't be using it.

2.  Copy driver/vufind_side/Unicorn.ini.example to your 'conf' directory
    on your VuFind server.  In a standard install, this will be:

    /usr/local/vufind/web/conf/

    Rename to file to Unicorn.ini, overwriting the included Unicorn.ini.

3.  Edit this Unicorn.ini file to reflect your local information.  The settings
    are:
    host - your Unicorn fully-qualified domain name
    port - the port on which your Apache-su is running (it is the number after
    the ':' in your driver URL (3000 in the example above)).
    search_prog - web path to your driver.pl file (the rest of the URL from
        above, minus the query).
    show_library - set to 1 to show your library in the location, set to 0 to
        leave the library out
    show_library_format - template string which determines how the library
        and location information will be combined, change according to
        preference

You are done.  Your changes should be live.  Please contact the list if you
have problems or suggestions.
