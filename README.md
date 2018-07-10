# Installer for a Lumberjack site with Bedrock

## Usage

Install this package globally via Composer:

```
composer global require rareloop/lumberjack-bedrock-installer
```

You can then scaffold out a new Lumberjack/Bedrock site using:

```
lumberjack-bedrock new my-site
```

The above command will create a folder in your current directory called `my-site` which contains Bedrock with Lumberjack ready to go.

## Options

- `--with-trellis`: Set up the project with [Trellis](https://roots.io/trellis/) for deployment.

## Verbosity

You can control the verbosity of messages output by the the installer. 

```bash
# increase the verbosity of messages
lumberjack-bedrock new my-site -v

# display all messages, including commands run (useful to debug errors)
lumberjack-bedrock new my-site -vvv
```
