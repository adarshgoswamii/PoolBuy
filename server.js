const http = require('http');
const fs = require('fs');
const path = require('path');

const PORT = process.env.PORT || 3000;

const ROUTE_ALIASES = {
  '/': 'index.html',
  '/index.php': 'stitch_cobuy_wholesale_marketplace/poolbuy_marketplace/code.html',
  '/landing': 'stitch_cobuy_wholesale_marketplace/poolbuy_landing_page/code.html',
  '/marketplace': 'stitch_cobuy_wholesale_marketplace/poolbuy_marketplace/code.html',
  '/b2b-marketplace': 'stitch_cobuy_wholesale_marketplace/poolbuy_wholesale_b2b_marketplace/code.html',
  '/product': 'stitch_cobuy_wholesale_marketplace/product_details_pool_status/code.html',
  '/join': 'stitch_cobuy_wholesale_marketplace/join_pool_checkout_flow/code.html',
  '/checkout': 'stitch_cobuy_wholesale_marketplace/join_pool_checkout_flow/code.html',
  '/buyer': 'stitch_cobuy_wholesale_marketplace/buyer_dashboard/code.html',
  '/buyer/dashboard': 'stitch_cobuy_wholesale_marketplace/buyer_dashboard/code.html',
  '/buyer-dashboard': 'stitch_cobuy_wholesale_marketplace/buyer_dashboard/code.html',
  '/seller': 'stitch_cobuy_wholesale_marketplace/seller_pool_management/code.html',
  '/seller/pools': 'stitch_cobuy_wholesale_marketplace/seller_pool_management/code.html',
  '/admin': 'stitch_cobuy_wholesale_marketplace/admin_dashboard/code.html',
  '/admin/dashboard': 'stitch_cobuy_wholesale_marketplace/admin_dashboard/code.html',
  '/protocol': 'stitch_cobuy_wholesale_marketplace/executive_wholesale_protocol/code.html',
};

// Map OpenCart ?route= query routes to corresponding prototypes
const OPENCART_ROUTE_MAP = {
  'extension/poolbuy/pool': 'stitch_cobuy_wholesale_marketplace/poolbuy_marketplace/code.html',
  'extension/poolbuy/join': 'stitch_cobuy_wholesale_marketplace/join_pool_checkout_flow/code.html',
  'extension/poolbuy/account': 'stitch_cobuy_wholesale_marketplace/buyer_dashboard/code.html',
  'extension/poolbuy/seller': 'stitch_cobuy_wholesale_marketplace/seller_pool_management/code.html',
  'extension/poolbuy/admin': 'stitch_cobuy_wholesale_marketplace/admin_dashboard/code.html',
  'common/home': 'stitch_cobuy_wholesale_marketplace/poolbuy_landing_page/code.html',
};

const MIME_TYPES = {
  '.html': 'text/html; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.json': 'application/json',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg',
  '.webp': 'image/webp',
  '.svg': 'image/svg+xml',
  '.woff': 'font/woff',
  '.woff2': 'font/woff2',
  '.ttf': 'font/ttf',
};

const server = http.createServer((req, res) => {
  const parsedUrl = new URL(req.url, `http://${req.headers.host || 'localhost'}`);
  const routeParam = parsedUrl.searchParams.get('route');

  let reqPath = decodeURI(parsedUrl.pathname);

  // Check OpenCart ?route= first
  if (routeParam && OPENCART_ROUTE_MAP[routeParam]) {
    reqPath = '/' + OPENCART_ROUTE_MAP[routeParam];
  } else if (ROUTE_ALIASES[reqPath]) {
    reqPath = '/' + ROUTE_ALIASES[reqPath];
  } else if (reqPath.endsWith('/') && ROUTE_ALIASES[reqPath.slice(0, -1)]) {
    reqPath = '/' + ROUTE_ALIASES[reqPath.slice(0, -1)];
  }

  // Handle internal documentation service proxy when DAUXIO_URL binding is active
  if ((reqPath === '/docs' || reqPath.startsWith('/docs/')) && process.env.DAUXIO_URL) {
    try {
      const docPath = reqPath.replace(/^\/docs/, '') || '/';
      const targetUrl = new URL(docPath + parsedUrl.search, process.env.DAUXIO_URL);
      const client = targetUrl.protocol === 'https:' ? require('https') : http;
      const proxyReq = client.request(targetUrl, {
        method: req.method,
        headers: {
          ...req.headers,
          host: targetUrl.host,
        },
      }, (proxyRes) => {
        res.writeHead(proxyRes.statusCode, proxyRes.headers);
        proxyRes.pipe(res);
      });
      proxyReq.on('error', (err) => {
        res.writeHead(502, { 'Content-Type': 'text/plain; charset=utf-8' });
        res.end(`502 Bad Gateway: Could not reach internal Daux.io service at ${process.env.DAUXIO_URL}: ${err.message}`);
      });
      req.pipe(proxyReq);
      return;
    } catch (e) {
      res.writeHead(500, { 'Content-Type': 'text/plain; charset=utf-8' });
      res.end(`500 Internal Error proxying to DAUXIO_URL: ${e.message}`);
      return;
    }
  }

  let filePath = path.join(__dirname, reqPath);

  if (fs.existsSync(filePath) && fs.statSync(filePath).isDirectory()) {
    const candidate = path.join(filePath, 'index.html');
    if (fs.existsSync(candidate)) {
      filePath = candidate;
    } else {
      const codeCandidate = path.join(filePath, 'code.html');
      if (fs.existsSync(codeCandidate)) {
        filePath = codeCandidate;
      }
    }
  }

  if (!fs.existsSync(filePath)) {
    res.writeHead(404, { 'Content-Type': 'text/html; charset=utf-8' });
    res.end(`<h1>404 Not Found</h1><p>Requested: ${req.url}</p><p><a href="/">Return to PoolBuy Suite Directory</a></p>`);
    return;
  }

  const ext = path.extname(filePath).toLowerCase();
  const contentType = MIME_TYPES[ext] || 'application/octet-stream';

  fs.readFile(filePath, (err, content) => {
    if (err) {
      res.writeHead(500, { 'Content-Type': 'text/plain' });
      res.end('Error reading file: ' + err.message);
    } else {
      res.writeHead(200, { 'Content-Type': contentType });
      res.end(content);
    }
  });
});

server.listen(PORT, () => {
  console.log(`PoolBuy Original UI Server running at http://localhost:${PORT}/`);
  console.log(`- Landing:     http://localhost:${PORT}/landing`);
  console.log(`- Marketplace: http://localhost:${PORT}/marketplace`);
  console.log(`- Buyer:       http://localhost:${PORT}/buyer`);
  console.log(`- Seller:      http://localhost:${PORT}/seller`);
  console.log(`- Admin:       http://localhost:${PORT}/admin`);
  console.log(`- Protocol:    http://localhost:${PORT}/protocol`);
});
