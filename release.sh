#! /bin/bash
set -euo pipefail
#
# Source: https://gist.github.com/philbuchanan/8188898
# License: GNU GPL 2.1
# Script to deploy from Github to WordPress.org Plugin Repository
# A modification of Dean Clatworthy's deploy script as found here: https://github.com/deanc/wordpress-plugin-git-svn
# The difference is that this script lives in the plugin's git repo & doesn't require an existing SVN repo.
# Slightly adapted

SVNUSER=razorpay
PLUGINSLUG='woo-razorpay'

# main config
CURRENTDIR="$(pwd)/"

# git config
GITPATH="$CURRENTDIR" # this file should be in the base of your git repository

# svn config
SVNPATH="/tmp/$PLUGINSLUG" # path to a temp SVN repo. No trailing slash required and don't add trunk.
# Remote SVN repo on wordpress.org, with no trailing slash
SVNURL="https://plugins.svn.wordpress.org/$PLUGINSLUG"

# Let's begin...
echo ".........................................."
echo
echo "Preparing to deploy wordpress plugin"
echo
echo ".........................................."
echo

# Check version in readme.txt
RELEASE_VERSION=$(grep "^Stable tag" "$GITPATH/readme.txt" | awk -F' ' '{print $3}')
COMMITMSG="Update: $RELEASE_VERSION"

echo "Tagging new version in git"
git tag -a "v$RELEASE_VERSION" -m "$COMMITMSG"

echo "Pushing latest commit to origin, with tags"
git push origin master
git push origin master --tags

echo "Creating local copy of SVN repo ..."
svn co $SVNURL $SVNPATH

echo "Exporting the HEAD of master from git to the trunk of SVN"
git checkout-index -a -f --prefix=$SVNPATH/trunk/

echo "Ignoring github specific & deployment script"
svn propset svn:ignore "release.sh
.git
.gitignore" "$SVNPATH/trunk/"

# TODO: move assets to git repo
# echo "Moving assets-wp-repo"
# mkdir $SVNPATH/assets/
# mv $SVNPATH/trunk/assets-wp-repo/* $SVNPATH/assets/
# svn add $SVNPATH/assets/
# svn delete $SVNPATH/trunk/assets-wp-repo

echo "Changing directory to SVN"
cd "$SVNPATH/trunk/"
# Add all new files that are not set to be ignored
svn status | grep -v "^.[ \t]*\..*" | grep "^?" | awk '{print $2}' | xargs svn add || true
echo "committing to trunk"
svn commit --username="$SVNUSER" -m "$COMMITMSG"

# echo "Updating WP plugin repo assets & committing"
# cd $SVNPATH/assets/
# svn commit --username=$SVNUSER -m "Updating wp-repo-assets"

echo "Creating new SVN tag & committing it"
cd "$SVNPATH"
svn copy trunk/ "tags/$RELEASE_VERSION"
cd "$SVNPATH/tags/$RELEASE_VERSION"
svn commit --username="$SVNUSER" -m "Tagging version $RELEASE_VERSION"

echo "*** FIN ***"
