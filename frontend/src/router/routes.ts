import type { RouteRecordRaw } from 'vue-router'
import AuthLayout from '@/layouts/AuthLayout.vue'
import MainLayout from '@/layouts/MainLayout.vue'

declare module 'vue-router' {
  interface RouteMeta {
    requiresAuth?: boolean
    guestOnly?: boolean
    /** Permission the backend grants (AuthUser.permissions); without it the guard sends to /403. */
    permission?: string
  }
}

export const routes: RouteRecordRaw[] = [
  {
    path: '/login',
    component: AuthLayout,
    children: [
      { path: '', name: 'login', component: () => import('@/pages/LoginPage.vue'), meta: { guestOnly: true } },
    ],
  },
  {
    path: '/',
    component: MainLayout,
    meta: { requiresAuth: true },
    children: [
      { path: '', redirect: { name: 'dashboard' } },
      {
        path: 'dashboard',
        name: 'dashboard',
        component: () => import('@/pages/DashboardPage.vue'),
        meta: { requiresAuth: true, permission: 'dashboard.view' },
      },
      {
        path: 'incidents',
        name: 'incidents',
        component: () => import('@/pages/IncidentsListPage.vue'),
        meta: { requiresAuth: true },
      },
      {
        path: 'incidents/new',
        name: 'incident-create',
        component: () => import('@/pages/IncidentFormPage.vue'),
        meta: { requiresAuth: true, permission: 'incidents.create' },
      },
      {
        path: 'incidents/:id(\\d+)',
        name: 'incident-detail',
        component: () => import('@/pages/IncidentDetailPage.vue'),
        meta: { requiresAuth: true },
      },
      {
        path: 'incidents/:id(\\d+)/edit',
        name: 'incident-edit',
        component: () => import('@/pages/IncidentFormPage.vue'),
        meta: { requiresAuth: true, permission: 'incidents.update' },
      },
      {
        path: '403',
        name: 'forbidden',
        component: () => import('@/pages/ForbiddenPage.vue'),
        meta: { requiresAuth: true },
      },
    ],
  },
  { path: '/:pathMatch(.*)*', name: 'not-found', component: () => import('@/pages/NotFoundPage.vue') },
]
