dir="/var/www/nmsprime"
cd $dir

# access rights
chown -R apache $dir/storage/ $dir/bootstrap/cache/

# reload apache config
systemctl reload httpd
