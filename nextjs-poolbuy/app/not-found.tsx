import Link from 'next/link';

export default function NotFound() {
  return (
    <div className="card flex flex-col items-center gap-3 p-12 text-center">
      <p className="text-5xl font-bold text-brand-600">404</p>
      <h1 className="text-lg font-semibold text-slate-800">Page not found</h1>
      <p className="text-sm text-slate-500">The pool or page you&apos;re looking for doesn&apos;t exist.</p>
      <Link href="/marketplace" className="btn-primary mt-2">
        Back to marketplace
      </Link>
    </div>
  );
}
