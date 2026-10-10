/** Consola de administración de WebAssistant (CG-11): SPA sin dependencias servida en /admin/. */
import { ConsoleApp, type Route } from './app';
import { accountPage, dashboardPage, loginView, tenantsPage } from './pages/basics';
import { agentsPage, assistantDetailPage, assistantsPage } from './pages/assistants';
import {
  auditPage,
  configPage,
  conversationsPage,
  governancePage,
  tracePage,
  usagePage,
} from './pages/governance';
import { documentsPage, knowledgePage } from './pages/knowledge';
import { platformPage } from './pages/platform';
import { clientsPage, sitesPage, usersPage } from './pages/tenant';
import './styles.css';

export const ROUTES: Route[] = [
  { pattern: '/', page: dashboardPage, nav: { label: 'Inicio', group: 'general' } },
  {
    pattern: '/t/:tid/assistants',
    page: assistantsPage,
    permission: 'config.read',
    nav: { label: 'Assistants', group: 'tenant' },
  },
  { pattern: '/t/:tid/assistants/:aid', page: assistantDetailPage, permission: 'config.read' },
  {
    pattern: '/t/:tid/agents',
    page: agentsPage,
    permission: 'config.read',
    nav: { label: 'Políticas de agente', group: 'tenant' },
  },
  {
    pattern: '/t/:tid/knowledge',
    page: knowledgePage,
    permission: 'knowledge.read',
    nav: { label: 'Conocimiento', group: 'tenant' },
  },
  { pattern: '/t/:tid/knowledge/:kbid', page: documentsPage, permission: 'knowledge.read' },
  {
    pattern: '/t/:tid/sites',
    page: sitesPage,
    permission: 'tenant.manage',
    nav: { label: 'Sites', group: 'tenant' },
  },
  {
    pattern: '/t/:tid/clients',
    page: clientsPage,
    permission: 'tenant.manage',
    nav: { label: 'Clientes API', group: 'tenant' },
  },
  {
    pattern: '/t/:tid/users',
    page: usersPage,
    permission: 'tenant.manage',
    nav: { label: 'Usuarios', group: 'tenant' },
  },
  {
    pattern: '/t/:tid/governance',
    page: governancePage,
    permission: 'tenant.manage',
    nav: { label: 'Políticas y retención', group: 'tenant' },
  },
  {
    pattern: '/t/:tid/usage',
    page: usagePage,
    permission: 'usage.read',
    nav: { label: 'Uso y costes', group: 'tenant' },
  },
  {
    pattern: '/t/:tid/conversations',
    page: conversationsPage,
    permission: 'conversations.read',
    nav: { label: 'Conversaciones', group: 'tenant' },
  },
  { pattern: '/t/:tid/conversations/:cid', page: tracePage, permission: 'conversations.read' },
  {
    pattern: '/t/:tid/audit',
    page: auditPage,
    permission: 'audit.read',
    nav: { label: 'Auditoría', group: 'tenant' },
  },
  {
    pattern: '/t/:tid/config',
    page: configPage,
    permission: 'config.read',
    nav: { label: 'Exportar / importar', group: 'tenant' },
  },
  {
    pattern: '/tenants',
    page: tenantsPage,
    permission: 'platform.manage',
    nav: { label: 'Tenants', group: 'platform' },
  },
  {
    pattern: '/platform',
    page: platformPage,
    permission: 'platform.manage',
    nav: { label: 'Precios, skills e índices', group: 'platform' },
  },
  { pattern: '/account', page: accountPage, nav: { label: 'Mi cuenta', group: 'account' } },
];

const root = document.getElementById('app');
if (root) void new ConsoleApp(root, ROUTES, loginView).start();
