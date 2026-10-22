#!/bin/bash
# Shared helpers for base-build-all.sh / base-rebuild-all.sh / base-build-all-dryrun.sh.
# Sourced by those scripts; not meant to be run directly.
# Requires SCRIPT_DIR (the packages/ directory) to be set by the sourcing script.

# Compiled runtime packages (core, async, ..., compiler) live under packages/tyhp/<pkg>.
# Generated dist trees stay at packages/dist/.
TYHP_PKG_DIR="$SCRIPT_DIR/tyhp"

# Dist package semver: MAJOR = PHP (802/803/804/805).
# Each package has its own independent X.Y in composer.json (not the compiler version):
#   source "0.0"              → dist "80N.0.0"
#   source "1.4"              → dist "80N.1.4"
#   source "805.1.0-beta.1"  → dist "80N.1.0-beta.1"  (legacy three-part: compiler MAJOR ignored)
PACKAGE_VERSION_MINOR="${PACKAGE_VERSION_MINOR:-}"
PACKAGE_VERSION_PATCH="${PACKAGE_VERSION_PATCH:-}"
PACKAGE_VERSION_SUFFIX="${PACKAGE_VERSION_SUFFIX:-}"

# php major : phpVersion CLI value : label
# Each target uses the package's tyhp.json with --output:phpVersion=.
# output.path interpolates {phpVersion.id} from that override.
DIST_BUILDS=(
  "802:8.2:PHP 8.2"
  "803:8.3:PHP 8.3"
  "804:8.4:PHP 8.4"
  "805:8.5:PHP 8.5"
)

# Packages built once from their own tyhp.json, which already points output.path at its
# final location. They get no per-PHP dist tree and no generated composer.json/README.
# Built after DIST_PACKAGES because they depend on those dist trees.
SINGLE_VERSION_PACKAGES=(compiler)

# Packages emitted into dist/tyhp-<pkg>/<version>/ once per PHP major.
DIST_PACKAGES=(core decimal async lambda parallel io)

# Optional package name from the command line. Empty means build every package.
SELECTED_PACKAGE=""

should_build_package() {
  [[ -z "${SELECTED_PACKAGE}" || "$1" == "${SELECTED_PACKAGE}" ]]
}

_print_package_usage() {
  echo "Usage: $(basename "$0") [package]"
  echo "  package  Optional. One of: ${DIST_PACKAGES[*]} ${SINGLE_VERSION_PACKAGES[*]}"
  echo "           If omitted, all packages are built. Dist packages still compile for every PHP version."
}

# Parse optional [package] from the invoking script's "$@". Exits on --help or invalid input.
require_package_selection() {
  if [[ $# -eq 0 ]]; then
    SELECTED_PACKAGE=""
    return 0
  fi

  if [[ $# -gt 1 ]]; then
    echo "Too many arguments." >&2
    _print_package_usage >&2
    exit 1
  fi

  local pkg="$1"
  if [[ "$pkg" == "-h" || "$pkg" == "--help" ]]; then
    _print_package_usage
    exit 0
  fi

  local candidate
  for candidate in "${DIST_PACKAGES[@]}" "${SINGLE_VERSION_PACKAGES[@]}"; do
    if [[ "$candidate" == "$pkg" ]]; then
      SELECTED_PACKAGE="$pkg"
      return 0
    fi
  done

  echo "Unknown package: $pkg" >&2
  echo "Valid packages: ${DIST_PACKAGES[*]} ${SINGLE_VERSION_PACKAGES[*]}" >&2
  exit 1
}

assert_selected_dist_package_versions() {
  local pkgs=()
  local pkg
  for pkg in "${DIST_PACKAGES[@]}"; do
    should_build_package "$pkg" || continue
    pkgs+=("$pkg")
  done
  if ((${#pkgs[@]} == 0)); then
    return 0
  fi
  echo "Package source versions (independent of the compiler):"
  assert_valid_package_versions "${pkgs[@]}"
}

# Parse source version into dist X.Y[-prerelease] parts.
# A.B → X=A Y=B. MAJOR.MINOR.PATCH[-pre] → X=MINOR Y=PATCH (PHP encoding is dist MAJOR).
_parse_package_source_version() {
  php "$SCRIPT_DIR/build-helpers.php" source-version-parts "$1"
}

# Read version from packages/tyhp/<pkg>/composer.json into
# PACKAGE_VERSION_MINOR / PACKAGE_VERSION_PATCH / PACKAGE_VERSION_SUFFIX.
# Always reloads — packages version independently and must not share the first load.
load_package_release_version() {
  local pkg="$1"
  local composer_json="$TYHP_PKG_DIR/$pkg/composer.json"
  local parsed
  local suffix
  local raw

  if [[ ! -f "$composer_json" ]]; then
    echo "composer.json not found: $composer_json" >&2
    return 1
  fi

  parsed="$(_parse_package_source_version "$composer_json")" || return 1

  IFS='|' read -r PACKAGE_VERSION_MINOR PACKAGE_VERSION_PATCH suffix raw <<< "$parsed"
  if [[ -n "$suffix" ]]; then
    PACKAGE_VERSION_SUFFIX="-${suffix}"
  else
    PACKAGE_VERSION_SUFFIX=""
  fi
}

# Read extra.tyhp.interopContractVersion (Story 15 interop contract) from a package
# composer.json. The source manifest is the single source of truth for the dist stamp so the
# two cannot drift; Tyhp's InteropContractSurfaceTests pin it to InteropContract.CurrentVersion.
read_package_interop_contract_version() {
  php "$SCRIPT_DIR/build-helpers.php" interop-contract-version "$1"
}

# Read extra.tyhp.require (Story 21.10 ambient tyhpdefs) from a package composer.json.
# Omitted or null becomes {}. Dist stamps this object so published packages match source.
read_package_tyhp_require() {
  php "$SCRIPT_DIR/build-helpers.php" tyhp-require "$1"
}

# Read extra.tyhp.package (tyhpdef load spec) from a package composer.json.
# Dist stamps this object so published packages load tyhpdefs from composer.json.
read_package_tyhp_package() {
  php "$SCRIPT_DIR/build-helpers.php" tyhp-package "$1"
}

# Read the raw source version string from a package composer.json.
read_package_source_version() {
  php "$SCRIPT_DIR/build-helpers.php" source-version "$1"
}

# Matching-X constraint across PHP majors for a dependency package (usually core).
# Uses that package's own X.Y — not the package currently being built.
matching_minor_constraint_for() {
  local dep_pkg="$1"
  local parsed
  local x
  parsed="$(_parse_package_source_version "$TYHP_PKG_DIR/$dep_pkg/composer.json")" || return 1
  IFS='|' read -r x _ _ _ <<< "$parsed"
  echo "802.${x}.* || 803.${x}.* || 804.${x}.* || 805.${x}.*"
}

core_version_constraint() {
  matching_minor_constraint_for "core"
}

async_version_constraint() {
  matching_minor_constraint_for "async"
}

assert_valid_package_versions() {
  local pkgs=("$@")
  local pkg
  local parsed
  local x
  local y
  local suffix
  local raw

  for pkg in "${pkgs[@]}"; do
    parsed="$(_parse_package_source_version "$TYHP_PKG_DIR/$pkg/composer.json")" || return 1
    IFS='|' read -r x y suffix raw <<< "$parsed"
    if [[ -n "$suffix" ]]; then
      suffix="-${suffix}"
    fi
    echo "  ${pkg}: ${raw} → dist 80N.${x}.${y}${suffix}"
  done
}

package_version() {
  local php_major="$1"
  if [[ -z "${PACKAGE_VERSION_MINOR}" || -z "${PACKAGE_VERSION_PATCH}" ]]; then
    echo "PACKAGE_VERSION_MINOR/PATCH not loaded; call load_package_release_version first" >&2
    return 1
  fi
  echo "${php_major}.${PACKAGE_VERSION_MINOR}.${PACKAGE_VERSION_PATCH}${PACKAGE_VERSION_SUFFIX}"
}

dist_package_dir() {
  local pkg="$1"
  local php_major="$2"
  echo "$SCRIPT_DIR/dist/tyhp-${pkg}/$(package_version "$php_major")"
}

php_constraint_for_major() {
  local label
  label="$(php_label_for_major "$1")" || return $?
  echo ">=${label}"
}

php_label_for_major() {
  case "$1" in
    802) echo "8.2" ;;
    803) echo "8.3" ;;
    804) echo "8.4" ;;
    805) echo "8.5" ;;
    *)
      echo "unknown PHP major: $1" >&2
      return 1
      ;;
  esac
}


write_dist_composer_json() {
  local pkg="$1"
  local php_major="$2"
  local out_dir
  local version
  local php_c
  local php_label
  local composer_name="tyhp/${pkg}"
  local core_constraint
  local interop_version
  local tyhp_require
  local tyhp_package

  out_dir="$(dist_package_dir "$pkg" "$php_major")"
  version="$(package_version "$php_major")"
  php_c="$(php_constraint_for_major "$php_major")"
  php_label="$(php_label_for_major "$php_major")"
  core_constraint="$(core_version_constraint)"
  interop_version="$(read_package_interop_contract_version "$TYHP_PKG_DIR/$pkg/composer.json")" || return 1
  tyhp_require="$(read_package_tyhp_require "$TYHP_PKG_DIR/$pkg/composer.json")" || return 1
  tyhp_package="$(read_package_tyhp_package "$TYHP_PKG_DIR/$pkg/composer.json")" || return 1

  mkdir -p "$out_dir"

  case "$pkg" in
    core)
      cat > "$out_dir/composer.json" <<EOF
{
    "name": "${composer_name}",
    "description": "Tyhp runtime core (PHP ${php_label}) — type system, generics, typed variables, property accessors",
    "type": "composer-plugin",
    "license": "Apache-2.0",
    "version": "${version}",
    "extra": {
        "tyhp": {
            "interopContractVersion": ${interop_version},
            "require": ${tyhp_require},
            "package": ${tyhp_package}
        },
        "class": "Tyhp\\\\Composer\\\\Plugin"
    },
    "require": {
        "php": "${php_c}",
        "composer-plugin-api": "^2.3"
    },
    "require-dev": {
        "tyhpdef/php": "@dev",
        "tyhpdef/php-ext-mbstring": "@dev"
    },
    "config": {
        "allow-plugins": {
            "tyhp/core": true
        }
    },
    "suggest": {
        "ext-mbstring": "Unicode-aware string methods (length, substring, toUpper, \u2026); byte APIs are used when missing"
    },
    "autoload": {
        "psr-4": {
            "Tyhp\\\\": "src/Tyhp/",
            "Tyhp\\\\Composer\\\\": "plugin/"
        },
        "files": [
            "src/_functions.php"
        ]
    }
}
EOF
      ;;
    async)
      cat > "$out_dir/composer.json" <<EOF
{
    "name": "${composer_name}",
    "description": "Tyhp runtime async (PHP ${php_label}) — Promise, event loop, CancellationToken, async iteration",
    "type": "library",
    "license": "Apache-2.0",
    "version": "${version}",
    "extra": {
        "tyhp": {
            "interopContractVersion": ${interop_version},
            "require": ${tyhp_require},
            "package": ${tyhp_package}
        }
    },
    "require": {
        "php": "${php_c}",
        "tyhp/core": "${core_constraint}"
    },
    "require-dev": {
        "tyhpdef/php": "@dev"
    },
    "config": {
        "allow-plugins": {
            "tyhp/core": true
        }
    },
    "autoload": {
        "psr-4": {
            "Tyhp\\\\": "src/Tyhp/"
        },
        "files": [
            "src/_functions.php"
        ]
    }
}
EOF
      ;;
    decimal)
      cat > "$out_dir/composer.json" <<EOF
{
    "name": "${composer_name}",
    "description": "Tyhp runtime decimal (PHP ${php_label}) — arbitrary-precision decimal arithmetic",
    "type": "library",
    "license": "Apache-2.0",
    "version": "${version}",
    "extra": {
        "tyhp": {
            "interopContractVersion": ${interop_version},
            "require": ${tyhp_require},
            "package": ${tyhp_package}
        }
    },
    "require": {
        "php": "${php_c}",
        "tyhp/core": "${core_constraint}"
    },
    "require-dev": {
        "tyhpdef/php": "@dev"
    },
    "suggest": {
        "ext-decimal": "Preferred backend (php-decimal / mpdecimal) for arbitrary-precision decimal arithmetic",
        "ext-bcmath": "Alternative backend for arbitrary-precision decimal arithmetic",
        "ext-gmp": "Alternative backend for arbitrary-precision decimal arithmetic"
    },
    "config": {
        "allow-plugins": {
            "tyhp/core": true
        }
    },
    "autoload": {
        "psr-4": {
            "Tyhp\\\\": "src/Tyhp/"
        },
        "files": [
            "src/Tyhp/_functions.php"
        ]
    }
}
EOF
      ;;
    lambda)
      cat > "$out_dir/composer.json" <<EOF
{
    "name": "${composer_name}",
    "description": "Tyhp runtime parsable lambdas (PHP ${php_label}): PropertyPath and Expression tree runtime classes",
    "type": "library",
    "license": "Apache-2.0",
    "version": "${version}",
    "extra": {
        "tyhp": {
            "interopContractVersion": ${interop_version},
            "require": ${tyhp_require},
            "package": ${tyhp_package}
        }
    },
    "require": {
        "php": "${php_c}",
        "tyhp/core": "${core_constraint}"
    },
    "require-dev": {
        "tyhpdef/php": "@dev"
    },
    "config": {
        "allow-plugins": {
            "tyhp/core": true
        }
    },
    "autoload": {
        "psr-4": {
            "Tyhp\\\\": "src/Tyhp/"
        },
        "classmap": [
            "php/EvalClosure.php"
        ]
    }
}
EOF
      ;;
    parallel)
      local async_constraint
      async_constraint="$(async_version_constraint)"
      cat > "$out_dir/composer.json" <<EOF
{
    "name": "${composer_name}",
    "description": "Tyhp runtime parallel (PHP ${php_label}) — process pool for isolated CPU work",
    "type": "library",
    "bin": [
        "bin/tyhp-parallel"
    ],
    "license": "Apache-2.0",
    "version": "${version}",
    "extra": {
        "tyhp": {
            "interopContractVersion": ${interop_version},
            "require": ${tyhp_require},
            "package": ${tyhp_package}
        }
    },
    "require": {
        "php": "${php_c}",
        "ext-pcntl": "*",
        "ext-posix": "*",
        "tyhp/core": "${core_constraint}",
        "tyhp/async": "${async_constraint}"
    },
    "require-dev": {
        "tyhpdef/php": "@dev"
    },
    "config": {
        "allow-plugins": {
            "tyhp/core": true
        }
    },
    "autoload": {
        "psr-4": {
            "Tyhp\\\\": "src/Tyhp/"
        }
    }
}
EOF
      ;;
    io)
      local async_constraint
      async_constraint="$(async_version_constraint)"
      cat > "$out_dir/composer.json" <<EOF
{
    "name": "${composer_name}",
    "description": "Tyhp runtime io (PHP ${php_label}) — HTTP, processes, files, sockets, Redis, and databases on the tyhp/async event loop",
    "type": "library",
    "license": "Apache-2.0",
    "version": "${version}",
    "extra": {
        "tyhp": {
            "interopContractVersion": ${interop_version},
            "require": ${tyhp_require},
            "package": ${tyhp_package}
        }
    },
    "require": {
        "php": "${php_c}",
        "tyhp/core": "${core_constraint}",
        "tyhp/async": "${async_constraint}"
    },
    "require-dev": {
        "tyhpdef/php": "@dev"
    },
    "suggest": {
        "ext-curl": "Required by HttpClient",
        "ext-pgsql": "Required by PostgresConnection",
        "ext-mysqli": "Required by MySqlConnection",
        "ext-pdo": "Required by PdoConnection",
        "tyhp/parallel": "Runs PdoConnection, Dns, and Offload work in worker processes instead of blocking the event loop"
    },
    "config": {
        "allow-plugins": {
            "tyhp/core": true
        }
    },
    "autoload": {
        "psr-4": {
            "Tyhp\\\\": "src/Tyhp/"
        }
    }
}
EOF
      ;;
    *)
      echo "unknown package: $pkg" >&2
      return 1
      ;;
  esac
}

write_dist_readme() {
  local pkg="$1"
  local php_major="$2"
  local out_dir
  local version
  local php_label
  local composer_name="tyhp/${pkg}"

  out_dir="$(dist_package_dir "$pkg" "$php_major")"
  version="$(package_version "$php_major")"
  php_label="$(php_label_for_major "$php_major")"

  cat > "$out_dir/README.md" <<EOF
# ${composer_name}

Tyhp runtime package \`${composer_name}\` (version \`${version}\`, compiled for PHP ${php_label}).

For documentation, guides, and more information, visit **https://tyhplang.com**.
EOF
}

write_dist_license() {
  local pkg="$1"
  local php_major="$2"
  local out_dir
  local license_src="${REPO_ROOT}/LICENSE.txt"

  out_dir="$(dist_package_dir "$pkg" "$php_major")"

  if [[ ! -f "$license_src" ]]; then
    echo "LICENSE.txt not found at: $license_src" >&2
    return 1
  fi

  cp "$license_src" "$out_dir/LICENSE"
}

# Copy package.tyhpdef into the dist package root. Dist composer.json is written
# separately; extra.tyhp.package on that file is the tyhpdef package manifest.
# Library package.tyhpdef is still copied from each package's source root per PHP
# target rather than generated into output.path at dist time.
copy_dist_tyhpdefs() {
  local pkg="$1"
  local php_major="$2"
  local out_dir
  local src_dir="$TYHP_PKG_DIR/$pkg"

  out_dir="$(dist_package_dir "$pkg" "$php_major")"

  if [[ -f "$src_dir/package.tyhpdef" ]]; then
    cp "$src_dir/package.tyhpdef" "$out_dir/package.tyhpdef"
  else
    echo "warning: missing $src_dir/package.tyhpdef (not copied)" >&2
  fi
}

# Copy the hand-written Composer plugin (not compiled from Tyhp) into published core artifacts.
copy_dist_plugin() {
  local pkg="$1"
  local php_major="$2"
  local out_dir
  local src_dir="$TYHP_PKG_DIR/$pkg/plugin"

  [[ "$pkg" == "core" ]] || return 0

  out_dir="$(dist_package_dir "$pkg" "$php_major")"
  if [[ ! -d "$src_dir" ]]; then
    echo "missing core plugin directory: $src_dir" >&2
    return 1
  fi

  rm -rf "$out_dir/plugin"
  cp -R "$src_dir" "$out_dir/plugin"
}

# Copy the worker-service launcher into published parallel artifacts.
copy_dist_cli() {
  local pkg="$1"
  local php_major="$2"
  local out_dir
  local src

  [[ "$pkg" == "parallel" ]] || return 0

  src="$TYHP_PKG_DIR/$pkg/bin/tyhp-parallel"
  if [[ ! -f "$src" ]]; then
    echo "missing parallel cli: $src" >&2
    return 1
  fi

  out_dir="$(dist_package_dir "$pkg" "$php_major")"
  mkdir -p "$out_dir/bin"
  cp "$src" "$out_dir/bin/tyhp-parallel"
  chmod +x "$out_dir/bin/tyhp-parallel"
}

# Hand-written PHP that compiles a lambda closure. Not emitted from Tyhp.
copy_dist_eval_closure() {
  local pkg="$1"
  local php_major="$2"
  local out_dir
  local src

  [[ "$pkg" == "lambda" ]] || return 0

  src="$TYHP_PKG_DIR/lambda/php/EvalClosure.php"
  if [[ ! -f "$src" ]]; then
    echo "missing eval closure: $src" >&2
    return 1
  fi

  out_dir="$(dist_package_dir "$pkg" "$php_major")"
  mkdir -p "$out_dir/php"
  cp "$src" "$out_dir/php/EvalClosure.php"
}

# Point dist/tyhp-<pkg>/<php_major>.latest at the version just built, so other packages
# can depend on a stable path (e.g. ../../dist/tyhp-core/805.latest) across version bumps.
# The link target is relative, keeping the dist tree relocatable.
link_dist_latest_major() {
  local pkg="$1"
  local php_major="$2"
  local version
  local link="$SCRIPT_DIR/dist/tyhp-${pkg}/${php_major}.latest"

  version="$(package_version "$php_major")" || return 1

  if [[ -L "$link" ]]; then
    rm -f "$link"
  elif [[ -e "$link" ]]; then
    echo "refusing to replace non-symlink: $link" >&2
    return 1
  fi

  ln -s "$version" "$link"
}

# Drop compiler build-state metadata — not part of the published package.
cleanup_dist_build_artifacts() {
  local pkg="$1"
  local php_major="$2"
  local out_dir
  out_dir="$(dist_package_dir "$pkg" "$php_major")"
  rm -f "$out_dir/src/tyhp-build-state.json"
}

finalize_dist_package() {
  local pkg="$1"
  local php_major="$2"

  write_dist_composer_json "$pkg" "$php_major"
  write_dist_readme "$pkg" "$php_major"
  write_dist_license "$pkg" "$php_major"
  copy_dist_tyhpdefs "$pkg" "$php_major"
  copy_dist_plugin "$pkg" "$php_major"
  copy_dist_cli "$pkg" "$php_major"
  copy_dist_eval_closure "$pkg" "$php_major"
  cleanup_dist_build_artifacts "$pkg" "$php_major"
  link_dist_latest_major "$pkg" "$php_major"
}

# Remove a previous dist tree. A full build calls this before any package so
# old 80N.x.y directories and .latest links cannot outlive a version bump.
# A single-package build leaves dist alone: that package may depend on a
# sibling that was already built.
clear_dist() {
  echo "==> Clearing dist/"
  rm -rf "$SCRIPT_DIR/dist"
  mkdir -p "$SCRIPT_DIR/dist"
}

# Path installs are symlinks into dist/ and packages/tyhpdef/. composer update
# dumps the autoload of whatever is already in vendor/ before it can replace
# those links, so a deleted dist version fails on src/_functions.php. Drop
# vendor/ and the lock, then let update install from the path repos and write
# a new lock.
reset_package_composer_install() {
  local pkg_dir="$TYHP_PKG_DIR/$1"

  echo "==> Resetting vendor and lock ($1)"
  rm -rf "$pkg_dir/vendor"
  rm -f "$pkg_dir/composer.lock"
}

# Refresh vendor/ from the package's composer.json before compiling so tyhpdef
# path-repos (tyhpdef/php, php-ext-*, dist trees) are present for the checker.
run_composer_update() {
  local pkg="$1"
  local code

  if [[ ! -f "$TYHP_PKG_DIR/$pkg/composer.json" ]]; then
    echo "composer.json not found: $TYHP_PKG_DIR/$pkg/composer.json" >&2
    return 1
  fi

  reset_package_composer_install "$pkg"

  echo "==> composer update ($pkg)"
  set +e
  (cd "$TYHP_PKG_DIR/$pkg" && composer update --no-interaction)
  code=$?
  set -e

  if [[ $code -ne 0 ]]; then
    echo "composer update failed for $pkg with exit code $code" >&2
    return "$code"
  fi
}

run_tyhp_build() {
  local pkg="$1"
  local php_major="$2"
  local php_version="$3"
  local label="$4"
  local code

  echo "==> Building $pkg ($label) -> dist/tyhp-${pkg}/$(package_version "$php_major")/"
  set +e
  (cd "$TYHP_PKG_DIR/$pkg" && dotnet "$TYHP_DLL" build --clean \
    --output:phpVersion="$php_version")
  code=$?
  set -e

  # 0 = success, 5 = success with warnings (Tyhp ExitCode.CompileWarning)
  if [[ $code -ne 0 && $code -ne 5 ]]; then
    echo "Build failed for $pkg ($label) with exit code $code" >&2
    return "$code"
  fi

  finalize_dist_package "$pkg" "$php_major"
}

# Build a SINGLE_VERSION_PACKAGES entry from its own tyhp.json. The package owns its
# output.path, composer.json, and README, so there is no dist finalization step.
# No --clean: output.path is a tracked in-repo directory, so a failed build must not
# leave it wiped.
run_single_version_build() {
  local pkg="$1"
  local code

  echo "==> Building $pkg"
  run_composer_update "$pkg" || return $?
  set +e
  (cd "$TYHP_PKG_DIR/$pkg" && dotnet "$TYHP_DLL" build)
  code=$?
  set -e

  # 0 = success, 5 = success with warnings (Tyhp ExitCode.CompileWarning)
  if [[ $code -ne 0 && $code -ne 5 ]]; then
    echo "Build failed for $pkg with exit code $code" >&2
    return "$code"
  fi
}

clear_package_cache() {
  local pkg="$1"
  local code

  echo "==> Clearing cache for $pkg"
  set +e
  (cd "$TYHP_PKG_DIR/$pkg" && dotnet "$TYHP_DLL" clear_cache)
  code=$?
  set -e

  if [[ $code -ne 0 ]]; then
    echo "clear_cache failed for $pkg with exit code $code" >&2
    return "$code"
  fi
}
