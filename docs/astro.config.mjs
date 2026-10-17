import { defineConfig } from 'astro/config';
import starlight from '@astrojs/starlight';

export default defineConfig({
  site: process.env.DOCS_SITE || 'https://yukazakiri.github.io',
  base: process.env.DOCS_BASE || '/lepton-agent',
  integrations: [
    starlight({
      title: 'Lepton Agent',
      description: 'Community-maintained Laravel SDK for Circle wallets and Arc. Practical guides, exact amounts, and honest settlement states.',
      favicon: '/favicon.svg',
      social: [{ icon: 'github', label: 'GitHub', href: 'https://github.com/yukazakiri/lepton-agent' }],
      editLink: { baseUrl: 'https://github.com/yukazakiri/lepton-agent/edit/main/docs/' },
      customCss: ['./src/styles/custom.css'],
      sidebar: [
        { label: 'Start here', items: [
          { label: 'Overview', slug: '' },
          { label: 'Installation', slug: 'getting-started/installation' },
          { label: 'Offline quickstart', slug: 'getting-started/quickstart' },
          { label: 'Choose a driver', slug: 'getting-started/drivers' },
        ] },
        { label: 'Practical guides', items: [
          { label: 'Developer REST setup', slug: 'guides/developer-wallets' },
          { label: 'CLI authentication', slug: 'guides/cli-authentication' },
          { label: 'Wallet lifecycle', slug: 'guides/wallets' },
          { label: 'Reliable payments', slug: 'guides/payments' },
          { label: 'Verified notifications', slug: 'guides/webhooks' },
          { label: 'Arc reads and amounts', slug: 'guides/arc' },
          { label: 'Signing and contracts', slug: 'guides/signing' },
          { label: 'Testing', slug: 'guides/testing' },
        ] },
        { label: 'Reference', items: [{ autogenerate: { directory: 'reference' } }] },
        { label: 'Project', items: [{ autogenerate: { directory: 'project' } }] },
      ],
    }),
  ],
});
