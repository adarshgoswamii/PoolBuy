'use client';

import React, { useState } from 'react';
import { useMarketplace } from '@/context/MarketplaceContext';

export default function AdminTaxonomyPage() {
  const { categories, addCategory, updateSubcategoryFilterSchema } = useMarketplace();

  const [selectedSubcategory, setSelectedSubcategory] = useState<string>('sub-1');
  const [jsonSchemaText, setJsonSchemaText] = useState<string>('');
  const [isSaved, setIsSaved] = useState(false);

  // New Category Form State
  const [newCatName, setNewCatName] = useState('');
  const [newCatSlug, setNewCatSlug] = useState('');
  const [newCatDesc, setNewCatDesc] = useState('');

  const activeSubcategory = categories
    .flatMap((c) => c.subcategories || [])
    .find((s) => s.id === selectedSubcategory);

  const handleSelectSubcategory = (subId: string) => {
    setSelectedSubcategory(subId);
    const sub = categories.flatMap((c) => c.subcategories || []).find((s) => s.id === subId);
    if (sub) {
      setJsonSchemaText(JSON.stringify(sub.filter_schema, null, 2));
    }
  };

  const handleSaveSchema = () => {
    try {
      const parsed = JSON.parse(jsonSchemaText);
      updateSubcategoryFilterSchema(selectedSubcategory, parsed);
      setIsSaved(true);
      setTimeout(() => setIsSaved(false), 2500);
    } catch {
      alert('Invalid JSON Schema. Please verify JSON format.');
    }
  };

  const handleAddCategory = (e: React.FormEvent) => {
    e.preventDefault();
    if (!newCatName || !newCatSlug) return;
    addCategory({
      name: newCatName,
      slug: newCatSlug,
      description: newCatDesc,
      icon: 'category',
    });
    setNewCatName('');
    setNewCatSlug('');
    setNewCatDesc('');
  };

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-black text-slate-900 tracking-tight font-display">Taxonomy &amp; Dynamic Filter Schema Builder</h1>
        <p className="text-xs text-slate-700 font-semibold mt-1">
          Define wholesale categories and configure dynamic JSONB filter schemas (attributes, palettes, GSM weights, certifications) without code deployments.
        </p>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-12 gap-8">
        {/* Left: Category Hierarchy */}
        <div className="lg:col-span-5 space-y-6">
          <div className="bg-white rounded-3xl border border-slate-200 p-6 shadow-card space-y-4">
            <h2 className="text-sm font-bold text-slate-900 uppercase tracking-wider pb-2 border-b border-slate-100 font-display">
              Active Category Hierarchy
            </h2>

            <div className="space-y-4">
              {categories.map((cat) => (
                <div key={cat.id} className="p-3.5 rounded-2xl bg-slate-50 border border-slate-200">
                  <div className="flex items-center justify-between mb-2">
                    <span className="font-bold text-slate-900 text-xs flex items-center gap-1.5">
                      <span className="material-symbols-outlined text-accent text-[18px]">{cat.icon || 'folder'}</span>
                      {cat.name}
                    </span>
                    <span className="font-mono text-[11px] text-slate-700 font-bold">/{cat.slug}</span>
                  </div>

                  {cat.subcategories && cat.subcategories.length > 0 && (
                    <div className="space-y-1.5 pl-6 border-l-2 border-accent/40">
                      {cat.subcategories.map((sub) => (
                        <button
                          key={sub.id}
                          onClick={() => handleSelectSubcategory(sub.id)}
                          className={`w-full text-left px-2.5 py-1.5 rounded-lg text-xs transition flex items-center justify-between ${
                            selectedSubcategory === sub.id
                              ? 'bg-accent text-white font-bold'
                              : 'text-slate-800 font-semibold hover:bg-slate-200'
                          }`}
                        >
                          <span className="truncate">{sub.name}</span>
                          <span className={`text-[10px] font-bold ${selectedSubcategory === sub.id ? 'text-white' : 'text-slate-700'}`}>
                            {sub.filter_schema?.filters?.length || 0} filters
                          </span>
                        </button>
                      ))}
                    </div>
                  )}
                </div>
              ))}
            </div>
          </div>

          {/* Quick Add Category */}
          <div className="bg-white rounded-3xl border border-slate-200 p-6 shadow-card space-y-4">
            <h3 className="text-xs font-bold text-slate-900 uppercase tracking-wider font-display">
              Add New Wholesale Category
            </h3>
            <form onSubmit={handleAddCategory} className="space-y-3 text-xs">
              <div>
                <label className="block text-slate-800 font-bold mb-1">Category Title</label>
                <input
                  type="text"
                  required
                  placeholder="e.g. Industrial Adhesives & Sealants"
                  value={newCatName}
                  onChange={(e) => setNewCatName(e.target.value)}
                  className="w-full px-3 py-2 rounded-xl border border-slate-300 bg-slate-50 focus:bg-white focus:outline-none focus:ring-2 focus:ring-accent font-medium text-slate-900"
                />
              </div>
              <div>
                <label className="block text-slate-800 font-bold mb-1">URL Slug</label>
                <input
                  type="text"
                  required
                  placeholder="e.g. industrial-adhesives"
                  value={newCatSlug}
                  onChange={(e) => setNewCatSlug(e.target.value)}
                  className="w-full px-3 py-2 rounded-xl border border-slate-300 bg-slate-50 focus:bg-white focus:outline-none focus:ring-2 focus:ring-accent font-mono font-bold text-slate-900"
                />
              </div>
              <button
                type="submit"
                className="w-full py-2.5 rounded-xl bg-[#0F1D36] text-amber-300 font-bold hover:bg-accent hover:text-white transition shadow-sm"
              >
                Create Category
              </button>
            </form>
          </div>
        </div>

        {/* Right: Dynamic JSONB Schema Editor */}
        <div className="lg:col-span-7">
          <div className="bg-white rounded-3xl border border-slate-200 p-6 shadow-card space-y-4 sticky top-20">
            <div className="flex items-center justify-between pb-3 border-b border-slate-100">
              <div>
                <h2 className="text-sm font-bold text-slate-900 uppercase tracking-wider font-display">
                  JSONB Filter Schema Editor
                </h2>
                <div className="text-xs text-accent font-bold mt-0.5">
                  Subcategory: {activeSubcategory?.name || 'Selected Subcategory'}
                </div>
              </div>

              <button
                onClick={handleSaveSchema}
                className="px-4 py-2 rounded-xl bg-accent text-white font-bold text-xs hover:bg-accent-dark transition shadow-sm flex items-center gap-1.5"
              >
                <span className="material-symbols-outlined text-[16px]">save</span>
                <span>Save Schema Changes</span>
              </button>
            </div>

            {isSaved && (
              <div className="p-3 rounded-xl bg-emerald-50 border border-emerald-200 text-xs font-semibold text-emerald-800 flex items-center gap-2">
                <span className="material-symbols-outlined text-[18px]">check_circle</span>
                Filter schema updated in database! Changes reflected in marketplace immediately.
              </div>
            )}

            <div>
              <label className="block text-xs font-mono font-bold text-slate-800 mb-1">
                PostgreSQL JSONB filter_schema definition:
              </label>
              <textarea
                rows={16}
                value={jsonSchemaText || (activeSubcategory ? JSON.stringify(activeSubcategory.filter_schema, null, 2) : '')}
                onChange={(e) => setJsonSchemaText(e.target.value)}
                className="w-full font-mono text-xs p-4 rounded-2xl bg-slate-950 text-emerald-400 border border-slate-800 focus:outline-none focus:ring-2 focus:ring-accent"
              />
            </div>

            <div className="p-3 rounded-xl bg-slate-50 border border-slate-300 text-xs text-slate-800 leading-relaxed">
              <strong>Schema Rule:</strong> Each filter item requires <code>key</code>, <code>label</code>, <code>type</code> (<code>single_select</code>, <code>multi_select</code>, <code>badge_select</code>, <code>color_palette</code>), and an <code>options</code> array.
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
