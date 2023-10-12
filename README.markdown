PHP5 Markdown
=============

A lightweight wiki built around the PHP Markdown class (credits to Michel Fortin and John Gruber).

Please consider other forks of this project on GitHub.


Features
--------

- Markdown
- Versioning
- Filesystem browsing
- File uploading, clipboard handling
- File renaming / deletion
- Link resolving (fetch the html title)


Screenshots
-----------

![scrn](README/image.png)
![scrn1](README/image.1.png)
![scrn2](README/image.2.png)
![scrn3](README/image.3.png)


Nginx Config
------------

    server {
        listen 80;
        server_name example.com;
        root /var/www;
        index index.php;

        location ~ /markdown/pages/ {
            deny all;
            return 404;
        }

        location ~ /markdown/ {
            auth_basic "PHP5 Markdown Wiki";
            auth_basic_user_file /etc/nginx/htpasswd;

            location ~ \.php(/.+)?$ {
                fastcgi_pass unix:/var/run/php5-fpm.sock;
                fastcgi_split_path_info ^(.+\.php)(/.+)$;
                fastcgi_param PATH_INFO $fastcgi_path_info;
                include fastcgi_params;
            }
        }
    }


---


To-do:
------

* Specifying a stylesheet
* Extract topmost header in document for use as a title
* Documentation of install
* Override layout rendering with templates
* Allow translations of interface (how are we doing UTF-8 wise?)
* Search
* Recent changes page
* Meta information: categorising, tagging, document title, author
* Improve test coverage of MarkdownWiki class
* Tighter/more secure file-update/conflict checking
* Documentation of layout templates / accessible data structures


Wish list:
----------

* REST-based API that deals with raw markdown
* Figure out a better way of extending the base markdown class.


Things to consider:
-------------------

* sub-content / shared modules
* Other text-format types ( textframe or textile )

