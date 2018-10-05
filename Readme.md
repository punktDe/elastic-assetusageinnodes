# PunktDe.Elastic.AssetUsageInNodes

[![Travis Build Status](https://travis-ci.org/punktDe/elastic-assetusageinnodes.svg?branch=master)](https://travis-ci.org/punktDe/elastic-assetusageinnodes) [![Latest Stable Version](https://poser.pugx.org/punktde/elastic-assetusageinnodes/v/stable)](https://packagist.org/packages/punktde/elastic-assetusageinnodes) [![Total Downloads](https://poser.pugx.org/punktde/elastic-assetusageinnodes/downloads)](https://packagist.org/packages/punktde/elastic-assetusageinnodes)

## Problem this package solves

In order to determine which assets are used in which content elements, Neos needs to do a full like search through all properties of the Neos ContentRepository table. 
For larger projects, having > 100.000 nodes, this can get awfully slow. A click on an asset  in the media module can take a minute and more. Scanning for unused assets using the `/flow media:removeunused` command can last for days.

## Solution

This package extracts used assets during the Elasticsearch indexing and replaces the expensive like search through a fast and effective Elasticsearch query.

### Installation

```
$ composer require punktde/elastic-assetusageinnodes
```
