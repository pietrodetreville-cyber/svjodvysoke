<?php // includes/footer.php ?>
  </div><!-- /container -->
  <footer style="text-align:center;padding:1.5rem;font-size:12px;color:var(--muted);border-top:1px solid var(--border);margin-top:2rem;">
    <?= e(SITE_NAME) ?> &nbsp;·&nbsp; <?= date('Y') ?> &nbsp;·&nbsp;
    <a href="mailto:vybor@<?= parse_url(SITE_URL, PHP_URL_HOST) ?>">Kontakt na výbor</a>
    &nbsp;·&nbsp;
    <span style="color:var(--border)">|</span>
    &nbsp;
    <span style="font-size:11px;color:var(--muted-2)">Systém vytvořil &copy; <?= date('Y') ?> <strong style="color:var(--muted)">Medusoft</strong></span>
  </footer>
</div><!-- /content -->
</div><!-- /shell -->
</body>
</html>
