#!/bin/bash
# Build the four libJudy arms bench/vendoring-probe.php compares.
#
# All arms come from ONE php-judy source tree with ONE toolchain; only
# --with-judy differs. Run inside a container that has phpize, gcc, objdump
# and the distro libjudy-dev, with the php-judy source at /work/src and
# Judy-1.0.5.tar.gz at /work (sha256 d2704089f85fdb6f2cd7e77be21170ced4b4375c03ef1ad4cf1075bd414a63eb).
#
#   C bundled          --with-judy=bundled       vendored+patched, static
#   B system           --with-judy=/usr          the distro's shared libJudy
#   P pristine-static  --with-judy=<ours,static> upstream 1.0.5, our flags
#   Q pristine-shared  --with-judy=<ours,shared> upstream 1.0.5, our flags, shared
#
# P exists because B confounds the vendored patches with linkage, hardening
# flags, compiler version and package config all at once. Q vs P isolates
# linkage alone; Q vs B isolates the distro build. See BENCHMARK.md.
#   C = bundled       libjudy/ compiled into judy.so   (the default; what PIE gives you)
#   B = system        --with-judy=/usr                 (links the distro's shared libJudy)
#   P = pristine      --with-judy=/work/pristine       (unpatched upstream 1.0.5, STATIC)
#
# P exists because B confounds two things: the vendored PATCHES and static-vs-shared
# linkage. P is static like C, so C-vs-P isolates the patches alone; C-vs-B is what a
# distro user actually experiences.
set -e
SRC=/work/src
OUT=/work/builds
mkdir -p "$OUT"

# The image's conf.d judy.ini would silently win over anything we load by path.
rm -f /usr/local/etc/php/conf.d/docker-php-ext-judy.ini

echo "=== TOOLCHAIN ==="
gcc --version | head -1
php -v | head -1
echo -n "system libJudy: "; dpkg -query -W -f='${Package} ${Version}\n' libjudy-dev 2>/dev/null || dpkg -l | grep -i judy || echo unknown
cat /etc/os-release | grep -E '^(PRETTY_NAME|VERSION_ID)'

# ---- pristine upstream Judy 1.0.5, static, -fPIC ---------------------------
#
# This arm is OPTIONAL. It sharpens the attribution (static-vs-static, so only
# the patches differ) but the required comparison is bundled-vs-system, and
# losing the whole campaign because an upstream 2009 autoconf script did not
# like a modern toolchain would be a poor trade. Failures here are reported in
# full and then skipped.
PRISTINE_OK=0
if [ -f /work/pristine/lib/libJudy.a ]; then
  PRISTINE_OK=1
else
  echo "=== BUILD pristine libJudy 1.0.5 (unpatched upstream) ==="
  ( set -e
    rm -rf /tmp/pj; mkdir -p /tmp/pj /work/pristine/lib /work/pristine/include
    tar xzf /work/Judy-1.0.5.tar.gz -C /tmp/pj
    cd /tmp/pj/judy-1.0.5
    # -fPIC so it can be linked into a shared object. The vendored tree's own
    # pinned flags are deliberately NOT applied: this arm is upstream as
    # upstream ships it, which is what the patches are being compared to.
    # -fno-strict-aliasing / -fno-aggressive-loop-optimizations stand in for
    # what modern gcc would otherwise miscompile in unpatched 1.0.5 — the
    # jp_1Index UB that Debian patch 04 fixes. Without them the arm is not
    # "upstream", it is "upstream, broken by a newer compiler".
    CFLAGS="-O2 -fPIC -fno-strict-aliasing -fno-aggressive-loop-optimizations" \
      ./configure --enable-static --disable-shared > /work/pristine-cfg.log 2>&1
    make -j8 > /work/pristine-make.log 2>&1
    A=$(find . -name libJudy.a | head -1)
    test -n "$A"
    cp "$A" /work/pristine/lib/
    cp src/Judy.h /work/pristine/include/
  ) && PRISTINE_OK=1
  if [ "$PRISTINE_OK" != "1" ]; then
    echo "PRISTINE BUILD FAILED — the bundled-vs-system comparison continues without it."
    echo "--- configure tail ---"; tail -25 /work/pristine-cfg.log 2>/dev/null
    echo "--- make tail ---";      tail -25 /work/pristine-make.log 2>/dev/null
  fi
fi
if [ "$PRISTINE_OK" = "1" ]; then
  echo -n "pristine libJudy.a: popcnt="; objdump -d /work/pristine/lib/libJudy.a | grep -c popcnt || true
  echo -n "pristine libJudy.a: bswap="; objdump -d /work/pristine/lib/libJudy.a | grep -c bswap || true
fi

build_arm () {
  local arm="$1" cfgflag="$2" tag="$3"
  echo "=== BUILD $arm ($cfgflag) tag=$tag ==="
  rm -rf /tmp/b-$arm-$tag
  cp -a "$SRC" /tmp/b-$arm-$tag
  cd /tmp/b-$arm-$tag
  rm -rf autom4te.cache configure config.h.in modules .libs
  phpize >/dev/null 2>&1
  ./configure $cfgflag --enable-judy >/tmp/cfg-$arm-$tag.log 2>&1 \
    || { echo "CONFIGURE FAILED"; tail -30 /tmp/cfg-$arm-$tag.log; exit 1; }
  make -j8 >/tmp/make-$arm-$tag.log 2>&1 \
    || { echo "MAKE FAILED"; tail -40 /tmp/make-$arm-$tag.log; exit 1; }
  echo "  warnings: $(grep -c 'warning:' /tmp/make-$arm-$tag.log || true)"
  cp modules/judy.so "$OUT/judy-$arm-$tag.so"
  echo -n "  ldd libJudy: "; ldd "$OUT/judy-$arm-$tag.so" | grep -i judy || echo "(none — statically linked in)"
  echo "  size: $(stat -c%s "$OUT/judy-$arm-$tag.so")  sha256: $(sha256sum "$OUT/judy-$arm-$tag.so" | cut -c1-16)"
  # Instruction-level arm identity: the vendored tree's O1 emits popcnt and its
  # O3 emits bswap. Those counts are the proof the arms differ as intended —
  # judy_version() is identical in all three and cannot show it.
  echo "  popcnt: $(objdump -d "$OUT/judy-$arm-$tag.so" | grep -c popcnt || true)  bswap: $(objdump -d "$OUT/judy-$arm-$tag.so" | grep -c bswap || true)"
}

for T in 1 2 3; do
  build_arm C "--with-judy=bundled"          "$T"
  build_arm B "--with-judy=/usr"             "$T"
  if [ "$PRISTINE_OK" = "1" ]; then
    build_arm P "--with-judy=/work/pristine" "$T"
  fi
done

# Instruction-level fingerprints, written where the campaign can quote them.
# judy_version() is identical in all three arms; these counts are the only
# machine-checkable evidence that the arms contain different libJudy code.
FP=/work/builds/fingerprints.txt
: > "$FP"
for A in C B P; do
  [ -f "$OUT/judy-$A-1.so" ] || continue
  PC=$(objdump -d "$OUT/judy-$A-1.so" | grep -c popcnt || true)
  BS=$(objdump -d "$OUT/judy-$A-1.so" | grep -c bswap || true)
  # A system-linked arm has no libJudy code of its own, so it must also show
  # UNDEFINED Judy symbols; a bundled/pristine arm must not.
  UND=$(nm -D --undefined-only "$OUT/judy-$A-1.so" 2>/dev/null | grep -c -i 'Judy1\|JudyL\|JudySL' || true)
  echo "judy.so[$A]: popcnt=$PC bswap=$BS undefined_judy_syms=$UND" >> "$FP"
done
SYSLIB=$(ldd "$OUT/judy-B-1.so" | grep -io '/\S*libJudy\S*' | head -1)
if [ -n "$SYSLIB" ]; then
  echo "system libJudy ($SYSLIB): popcnt=$(objdump -d "$SYSLIB" | grep -c popcnt || true) bswap=$(objdump -d "$SYSLIB" | grep -c bswap || true)" >> "$FP"
fi
[ "$PRISTINE_OK" = "1" ] && echo "pristine libJudy.a: popcnt=$(objdump -d /work/pristine/lib/libJudy.a | grep -c popcnt || true) bswap=$(objdump -d /work/pristine/lib/libJudy.a | grep -c bswap || true)" >> "$FP"
echo "=== FINGERPRINTS ==="; cat "$FP"

echo "=== SYSTEM libJudy.so instruction counts (arm B's library lives here) ==="
SYS=$(ldd "$OUT/judy-B-1.so" | grep -io '/\S*libJudy\S*' | head -1)
echo "  $SYS"
echo "  popcnt: $(objdump -d "$SYS" | grep -c popcnt || true)  bswap: $(objdump -d "$SYS" | grep -c bswap || true)"

echo "=== DONE ==="
ls -la "$OUT"

# ---- arm Q ----
OUT=/work/builds

if [ ! -f /work/pristine-shared/lib/libJudy.so ]; then
  echo "=== BUILD pristine libJudy 1.0.5 SHARED, our flags ==="
  rm -rf /tmp/pjs; mkdir -p /tmp/pjs /work/pristine-shared/lib /work/pristine-shared/include
  tar xzf /work/Judy-1.0.5.tar.gz -C /tmp/pjs
  cd /tmp/pjs/judy-1.0.5
  CFLAGS="-O2 -fPIC -fno-strict-aliasing -fno-aggressive-loop-optimizations" \
    ./configure --enable-shared --disable-static --prefix=/work/pristine-shared \
    > /work/pshared-cfg.log 2>&1
  # Only src/. Judy 1.0.5's `doc` target builds man-page symlinks and fails on a
  # modern toolchain; it has nothing to do with the library, and letting it take
  # the build down would cost the arm for no reason.
  make -C src -j8 > /work/pshared-make.log 2>&1 || { tail -20 /work/pshared-make.log; exit 1; }
  # libtool's output location varies across autotools versions, so find it
  # rather than assume a path — and say what IS there if nothing matches.
  FOUND=$(find . -name 'libJudy.so*' | head -20)
  if [ -z "$FOUND" ]; then
    echo "no libJudy.so* produced. Candidates present:"
    find . -name 'libJudy*' | head -20
    exit 1
  fi
  echo "$FOUND" | while read -r f; do cp -a "$f" /work/pristine-shared/lib/; done
  cp src/Judy.h /work/pristine-shared/include/
  ( cd /work/pristine-shared/lib && ls | grep -q '^libJudy.so$' || ln -sf "$(ls libJudy.so.* | head -1)" libJudy.so )
fi
ls -la /work/pristine-shared/lib/
echo "pristine-shared libJudy.so: popcnt=$(objdump -d /work/pristine-shared/lib/libJudy.so | grep -c popcnt || true) bswap=$(objdump -d /work/pristine-shared/lib/libJudy.so | grep -c bswap || true)"

for T in 1 2 3; do
  echo "=== BUILD Q tag=$T ==="
  rm -rf /tmp/b-Q-$T; cp -a "$SRC" /tmp/b-Q-$T; cd /tmp/b-Q-$T
  rm -rf autom4te.cache configure config.h.in modules .libs
  phpize >/dev/null 2>&1
  ./configure --with-judy=/work/pristine-shared --enable-judy > /tmp/cfg-Q-$T.log 2>&1 \
    || { tail -20 /tmp/cfg-Q-$T.log; exit 1; }
  make -j8 > /tmp/mk-Q-$T.log 2>&1 || { tail -30 /tmp/mk-Q-$T.log; exit 1; }
  echo "  warnings: $(grep -c 'warning:' /tmp/mk-Q-$T.log || true)"
  cp modules/judy.so "$OUT/judy-Q-$T.so"
  echo -n "  ldd libJudy: "; ldd "$OUT/judy-Q-$T.so" | grep -i judy || echo "(none - STATIC, WRONG for this arm)"
  echo "  popcnt=$(objdump -d "$OUT/judy-Q-$T.so"|grep -c popcnt||true) bswap=$(objdump -d "$OUT/judy-Q-$T.so"|grep -c bswap||true) undef=$(nm -D --undefined-only "$OUT/judy-Q-$T.so" 2>/dev/null | grep -c -i 'Judy1\|JudyL\|JudySL' || true)"
done
echo "=== Q DONE ==="
